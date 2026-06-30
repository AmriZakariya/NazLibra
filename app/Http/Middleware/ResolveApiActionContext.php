<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use App\Models\VirtualDevice;
use App\Support\ApiActionContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveApiActionContext
{
    public function handle(Request $request, Closure $next, ?string $ability = null): Response
    {
        $tenant = $request->attributes->get('api_tenant');
        $location = $request->attributes->get('api_location');
        $actor = $request->user();

        if (! $tenant || ! $actor) {
            return response()->json(['ok' => false, 'error' => 'action_context_unavailable', 'message' => 'Contexte utilisateur indisponible.'], 401);
        }

        if ($ability && ! $actor->tokenCan($ability)) {
            return response()->json(['ok' => false, 'error' => 'ability_denied', 'message' => 'Action non autorisée pour cet opérateur.'], 403);
        }

        if ($request->exists('user_id')) {
            return response()->json(['ok' => false, 'error' => 'client_actor_forbidden', 'message' => "L'utilisateur est déterminé par le jeton authentifié."], 422);
        }

        $enabled = (bool) data_get($tenant->settings, 'features.virtual_devices', true);
        $header = trim((string) $request->header('X-Virtual-Device-Id', ''));

        if ($enabled && $header === '') {
            return response()->json(['ok' => false, 'error' => 'virtual_device_required', 'message' => 'Sélectionnez un terminal virtuel actif.'], 422);
        }

        $device = null;
        if ($header !== '') {
            if (! ctype_digit($header) || (int) $header < 1) {
                return response()->json(['ok' => false, 'error' => 'invalid_virtual_device', 'message' => 'Terminal virtuel invalide.'], 422);
            }

            $device = VirtualDevice::query()
                ->where('tenant_id', $tenant->id)
                ->whereKey((int) $header)
                ->where('is_active', true)
                ->first();

            if (! $device || ($device->location_id && $device->location_id !== $location?->id)) {
                return response()->json(['ok' => false, 'error' => 'invalid_virtual_device', 'message' => "Ce terminal n'est pas actif ou autorisé pour cet emplacement."], 422);
            }
        }

        $context = new ApiActionContext($tenant, $actor, $location, $device);
        $request->attributes->set('api_action_context', $context);

        $response = $next($request);

        if ($response->getStatusCode() < 400) {
            AuditLog::create([
                'tenant_id' => $tenant->id,
                'user_id' => $actor->id,
                'action' => 'api.'.strtolower($request->method()),
                'friendly_action' => $request->method().' /'.$request->path(),
                'properties' => [
                    'path' => '/'.$request->path(),
                    'method' => $request->method(),
                    'location_id' => $location?->id,
                    'idempotency_key' => $request->input('idempotency_key'),
                    'response_status' => $response->getStatusCode(),
                ],
                'virtual_device_id' => $device?->id,
                'device_name_snapshot' => $device?->name,
                'device_code_snapshot' => $device?->code,
                'real_device_ip' => $request->ip(),
                'real_device_user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
            ]);
        }

        return $response;
    }
}
