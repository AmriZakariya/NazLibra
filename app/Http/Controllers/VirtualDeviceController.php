<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\VirtualDevice;
use App\Models\VirtualDeviceSession;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class VirtualDeviceController extends Controller
{
    private int $heartbeatTimeoutSeconds = 120;

    private function tenant(): Tenant
    {
        return auth()->user()?->currentTenant ?: Tenant::query()->firstOrFail();
    }

    private function isOwner(): bool
    {
        $tenant = $this->tenant();
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        $tenantUser = $tenant->users()->whereKey($user->id)->first();

        return (string) ($tenantUser?->pivot?->role ?? '') === 'owner';
    }

    private function isModuleEnabled(?Tenant $tenant = null): bool
    {
        $tenant ??= $this->tenant();

        return (bool) data_get($tenant->settings, 'features.virtual_devices', false);
    }

    private function ensureModuleEnabled(?Tenant $tenant = null): void
    {
        abort_unless($this->isModuleEnabled($tenant), 404);
    }

    // ─── Management (owner only) ─────────────────────────────────────

    public function index(): View
    {
        abort_unless($this->isOwner(), 403);

        $tenant = $this->tenant();
        $this->ensureModuleEnabled($tenant);
        $this->cleanStaleConnections($tenant);

        $devices = VirtualDevice::where('tenant_id', $tenant->id)
            ->with(['activeSession.user'])
            ->orderBy('name')
            ->get();

        return view('virtual-devices.manage', [
            'tenant' => $tenant,
            'devices' => $devices,
            'active' => 'settings',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->isOwner(), 403);

        $tenant = $this->tenant();
        $this->ensureModuleEnabled($tenant);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['nullable', 'in:computer,tablet,mobile,other'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $code = Str::slug($data['name']);
        $original = $code;
        $i = 2;
        while (VirtualDevice::where('tenant_id', $tenant->id)->where('code', $code)->exists()) {
            $code = $original.'-'.$i;
            $i++;
        }

        VirtualDevice::create([
            'tenant_id' => $tenant->id,
            'name' => $data['name'],
            'code' => $code,
            'type' => $data['type'] ?? 'other',
            'description' => $data['description'] ?? null,
            'is_active' => true,
        ]);

        return back()->with('status', 'Appareil créé.');
    }

    public function update(Request $request, VirtualDevice $device): RedirectResponse
    {
        abort_unless($this->isOwner(), 403);
        $tenant = $this->tenant();
        $this->ensureModuleEnabled($tenant);
        abort_unless($device->tenant_id === $tenant->id, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['nullable', 'in:computer,tablet,mobile,other'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $device->update($data);

        return back()->with('status', 'Appareil mis à jour.');
    }

    public function toggleStatus(VirtualDevice $device): RedirectResponse
    {
        abort_unless($this->isOwner(), 403);
        $tenant = $this->tenant();
        $this->ensureModuleEnabled($tenant);
        abort_unless($device->tenant_id === $tenant->id, 404);

        $device->update(['is_active' => ! $device->is_active]);

        if (! $device->is_active) {
            $this->disconnectAllSessions($device, 'device_deactivated');
        }

        return back()->with('status', $device->is_active ? 'Appareil activé.' : 'Appareil désactivé.');
    }

    public function destroy(VirtualDevice $device): RedirectResponse
    {
        abort_unless($this->isOwner(), 403);
        $tenant = $this->tenant();
        $this->ensureModuleEnabled($tenant);
        abort_unless($device->tenant_id === $tenant->id, 404);

        $this->disconnectAllSessions($device, 'device_deleted');

        $device->delete();

        return redirect()->route('devices.index')->with('status', 'Appareil supprimé.');
    }

    // ─── Device selection & connection ───────────────────────────────

    public function selectDevice(Request $request): View|RedirectResponse
    {
        $tenant = $this->tenant();
        $user = auth()->user();

        if (! $this->isModuleEnabled($tenant)) {
            $request->session()->forget('virtual_device_session_id');

            return redirect()->intended(route('dashboard'))->with('status', 'Le module appareils virtuels est désactivé.');
        }

        $this->cleanStaleConnections($tenant);

        $currentSession = $this->currentDeviceSession($tenant, $user);

        if ($currentSession && $currentSession->virtualDevice?->is_active) {
            return redirect()->intended(route('dashboard'));
        }

        $activeSessionIds = VirtualDeviceSession::where('tenant_id', $tenant->id)
            ->whereNull('disconnected_at')
            ->where('last_seen_at', '>=', now()->subSeconds($this->heartbeatTimeoutSeconds))
            ->pluck('virtual_device_id');

        $devices = VirtualDevice::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(function (VirtualDevice $device) use ($activeSessionIds) {
                $device->is_in_use = $activeSessionIds->contains($device->id);

                return $device;
            });

        return view('virtual-devices.select', [
            'tenant' => $tenant,
            'devices' => $devices,
            'currentSession' => $currentSession,
        ]);
    }

    public function connectDevice(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $user = auth()->user();
        $this->ensureModuleEnabled($tenant);

        $data = $request->validate([
            'virtual_device_id' => ['required', 'integer', Rule::exists('virtual_devices', 'id')->where('tenant_id', $tenant->id)],
        ]);

        $device = VirtualDevice::where('tenant_id', $tenant->id)->findOrFail($data['virtual_device_id']);

        if (! $device->is_active) {
            throw ValidationException::withMessages([
                'virtual_device_id' => 'Cet appareil est désactivé.',
            ]);
        }

        return DB::transaction(function () use ($tenant, $user, $device, $request) {
            $this->cleanStaleConnections($tenant);

            $alreadyConnected = VirtualDeviceSession::where('tenant_id', $tenant->id)
                ->where('virtual_device_id', $device->id)
                ->whereNull('disconnected_at')
                ->where('last_seen_at', '>=', now()->subSeconds($this->heartbeatTimeoutSeconds))
                ->lockForUpdate()
                ->exists();

            if ($alreadyConnected) {
                throw ValidationException::withMessages([
                    'virtual_device_id' => 'Cet appareil est déjà connecté par un autre utilisateur.',
                ]);
            }

            $existingSession = $this->currentDeviceSession($tenant, $user);

            if ($existingSession) {
                $this->disconnectSession($existingSession, 'switched_device');
            }

            $info = $this->detectDeviceInfo($request);
            $sessionId = $request->session()->getId();

            $session = VirtualDeviceSession::create([
                'tenant_id' => $tenant->id,
                'virtual_device_id' => $device->id,
                'user_id' => $user->id,
                'session_id' => $sessionId,
                'connection_token' => Str::uuid()->toString(),
                'user_agent' => $info['user_agent'],
                'platform' => $info['platform'],
                'browser' => $info['browser'],
                'ip_address' => $request->ip(),
                'metadata' => $info['metadata'],
                'connected_at' => now(),
                'last_seen_at' => now(),
            ]);

            $request->session()->put('virtual_device_session_id', $session->id);

            return redirect()->intended(route('dashboard'))->with('status', 'Connecté à '.$device->name.'.');
        });
    }

    public function disconnectDevice(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $user = auth()->user();

        $session = $this->currentDeviceSession($tenant, $user);

        if ($session) {
            $this->disconnectSession($session, 'manual');
            $request->session()->forget('virtual_device_session_id');
        }

        return $this->isModuleEnabled($tenant)
            ? redirect()->route('device.select')->with('status', 'Déconnecté.')
            : redirect()->route('dashboard')->with('status', 'Déconnecté.');
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $tenant = $this->tenant();
        $user = auth()->user();

        if (! $this->isModuleEnabled($tenant)) {
            $request->session()->forget('virtual_device_session_id');

            return response()->json(['ok' => true, 'disabled' => true]);
        }

        $session = $this->currentDeviceSession($tenant, $user);

        if (! $session) {
            return response()->json(['ok' => false, 'message' => 'Aucune session active.'], 404);
        }

        if (! $session->virtualDevice?->is_active) {
            $this->disconnectSession($session, 'device_deactivated');
            $request->session()->forget('virtual_device_session_id');

            return response()->json(['ok' => false, 'deactivated' => true, 'message' => 'Appareil désactivé.'], 410);
        }

        $session->update(['last_seen_at' => now()]);

        return response()->json(['ok' => true]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    private function currentDeviceSession(Tenant $tenant, $user): ?VirtualDeviceSession
    {
        $sessionId = session('virtual_device_session_id');

        if (! $sessionId) {
            return null;
        }

        $session = VirtualDeviceSession::where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('id', $sessionId)
            ->whereNull('disconnected_at')
            ->with('virtualDevice')
            ->first();

        if ($session && $session->isActive($this->heartbeatTimeoutSeconds)) {
            return $session;
        }

        if ($session) {
            $this->disconnectSession($session, 'stale');
        }

        session()->forget('virtual_device_session_id');

        return null;
    }

    private function disconnectSession(VirtualDeviceSession $session, string $reason): void
    {
        $session->disconnect($reason);
    }

    private function disconnectAllSessions(VirtualDevice $device, string $reason): void
    {
        VirtualDeviceSession::where('virtual_device_id', $device->id)
            ->whereNull('disconnected_at')
            ->update([
                'disconnected_at' => now(),
                'disconnect_reason' => $reason,
            ]);
    }

    private function cleanStaleConnections(Tenant $tenant): void
    {
        $stale = VirtualDeviceSession::where('tenant_id', $tenant->id)
            ->whereNull('disconnected_at')
            ->where('last_seen_at', '<', now()->subSeconds($this->heartbeatTimeoutSeconds))
            ->get();

        foreach ($stale as $session) {
            $session->disconnect('stale');
        }
    }

    private function detectDeviceInfo(Request $request): array
    {
        $userAgent = $request->userAgent() ?? 'Unknown';

        $platform = 'Unknown';
        $browser = 'Unknown';
        $metadata = ['raw_ua' => $userAgent];

        if (preg_match('/Windows/i', $userAgent)) {
            $platform = 'Windows';
        } elseif (preg_match('/Mac(intosh| OS X)/i', $userAgent)) {
            $platform = 'macOS';
        } elseif (preg_match('/Linux/i', $userAgent) && ! preg_match('/Android/i', $userAgent)) {
            $platform = 'Linux';
        } elseif (preg_match('/Android/i', $userAgent)) {
            $platform = 'Android';
        } elseif (preg_match('/iPhone|iPad|iPod/i', $userAgent)) {
            $platform = 'iOS';
        }

        if (preg_match('/(Edge|Edg)\/([\d.]+)/i', $userAgent, $m)) {
            $browser = 'Edge';
            $metadata['browser_version'] = $m[2];
        } elseif (preg_match('/Chrome\/([\d.]+)/i', $userAgent, $m) && ! preg_match('/Edg/i', $userAgent)) {
            $browser = 'Chrome';
            $metadata['browser_version'] = $m[1];
        } elseif (preg_match('/Firefox\/([\d.]+)/i', $userAgent, $m)) {
            $browser = 'Firefox';
            $metadata['browser_version'] = $m[1];
        } elseif (preg_match('/Safari\/([\d.]+)/i', $userAgent, $m) && ! preg_match('/Chrome/i', $userAgent)) {
            $browser = 'Safari';
            $metadata['browser_version'] = $m[1];
        }

        return [
            'user_agent' => mb_substr($userAgent, 0, 500),
            'platform' => $platform,
            'browser' => $browser,
            'metadata' => $metadata,
        ];
    }
}
