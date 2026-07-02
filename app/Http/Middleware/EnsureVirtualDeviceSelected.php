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

        $sessionId = $request->session()->get('virtual_device_session_id');

        if (! $sessionId) {
            return $this->requireDeviceSelection($request);
        }

        $session = VirtualDeviceSession::where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('id', $sessionId)
            ->whereNull('disconnected_at')
            ->with('virtualDevice')
            ->first();

        if (! $session || ! $session->virtualDevice?->is_active) {
            $request->session()->forget('virtual_device_session_id');

            if ($session) {
                $session->update([
                    'disconnected_at' => now(),
                    'disconnect_reason' => 'device_unavailable',
                ]);
            }

            return $this->requireDeviceSelection($request);
        }

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
