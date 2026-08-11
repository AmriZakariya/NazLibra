<?php

namespace App\Http\Middleware;

use App\Models\VirtualDevice;
use App\Models\VirtualDeviceSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureVirtualDeviceSelected
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $routeName = $request->route()?->getName();

        if (in_array($routeName, ['device.select', 'device.connect', 'device.disconnect', 'device.heartbeat', 'devices.index', 'devices.store', 'devices.update', 'devices.toggle', 'devices.disconnect', 'devices.destroy', 'profile.activity.data'], true)) {
            return $next($request);
        }

        $tenant = $user->currentTenant;

        if (! $tenant) {
            return $next($request);
        }

        if (! (bool) data_get($tenant->settings, 'features.virtual_devices', false)) {
            $request->session()->forget('virtual_device_session_id');

            return $next($request);
        }

        if (! VirtualDevice::where('tenant_id', $tenant->id)->where('is_active', true)->exists()) {
            return $next($request);
        }

        $base = VirtualDeviceSession::where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->whereNull('disconnected_at') // lenient: don't evict an idle owner mid-work
            ->with('virtualDevice');

        $sessionId = $request->session()->get('virtual_device_session_id');

        $session = $sessionId ? (clone $base)->whereKey($sessionId)->first() : null;

        // The Laravel session cookie can rotate or expire (regenerate on login,
        // lifetime, etc.). Rather than bounce a user who is still actively
        // connected, recover their most recent live session and rebind it.
        if (! $session) {
            $session = (clone $base)->live()->latest('last_seen_at')->first();
        }

        if (! $session || ! $session->virtualDevice?->is_active) {
            $request->session()->forget('virtual_device_session_id');

            if ($session && ! $session->virtualDevice?->is_active) {
                $session->update([
                    'disconnected_at' => now(),
                    'disconnect_reason' => 'device_unavailable',
                ]);
            }

            return $this->requireDeviceSelection($request);
        }

        // Keep the cookie pointing at the recovered/confirmed session.
        $request->session()->put('virtual_device_session_id', $session->id);

        return $next($request);
    }

    private function requireDeviceSelection(Request $request): Response
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Veuillez sélectionner un appareil.',
                'redirect' => route('device.select'),
            ], 428);
        }

        return redirect()->route('device.select');
    }
}
