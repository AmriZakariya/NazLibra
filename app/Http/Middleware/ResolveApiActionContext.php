<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use App\Models\VirtualDevice;
use App\Support\ApiActionContext;
use App\Support\RequestSnapshot;
use App\Support\TenantClock;
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

        $enabled = (bool) data_get($tenant->settings, 'features.virtual_devices', false);
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
            $this->record($request, $response, $context);
        }

        return $response;
    }

    /**
     * The same detail the back office keeps for its own actions, plus which
     * build of the app sent it and from which device. A journal that cannot
     * be written never undoes the action it describes.
     */
    private function record(Request $request, Response $response, ApiActionContext $context): void
    {
        try {
            $app = RequestSnapshot::appClient($request);
            $route = $request->route();

            AuditLog::create([
                'tenant_id' => $context->tenant->id,
                'user_id' => $context->actor->id,
                'action' => 'api.'.strtolower($request->method()),
                'friendly_action' => $request->method().' /'.$request->path(),
                'properties' => [
                    'method' => $request->method(),
                    'url' => $request->fullUrl(),
                    'path' => '/'.$request->path(),
                    'route' => $route?->getName() ?: $route?->uri(),
                    'route_action' => $route?->getActionName(),
                    'status_code' => $response->getStatusCode(),
                    'timezone' => TenantClock::timezone($context->tenant),
                    'ip' => $request->ip(),
                    'content_type' => $request->headers->get('content-type'),
                    'accept' => $request->headers->get('accept'),
                    'is_ajax' => false,
                    'user_agent' => mb_substr((string) $request->userAgent(), 0, 240),
                    'location_id' => $context->location?->id,
                    'location' => $context->location?->name,
                    'idempotency_key' => $request->input('idempotency_key'),
                    'app_version' => $app['version'],
                    'app_platform' => $app['platform'],
                    'app_device' => $app['device'],
                    'query' => RequestSnapshot::query($request),
                    'headers' => RequestSnapshot::headers($request),
                    'payload' => RequestSnapshot::payload($request),
                    'route_parameters' => RequestSnapshot::routeParameters($request),
                ],
                'virtual_device_id' => $context->virtualDevice?->id,
                'device_name_snapshot' => $context->virtualDevice?->name,
                'device_code_snapshot' => $context->virtualDevice?->code,
                'real_device_platform' => $app['device'] ?? ($app['platform'] ? ucfirst($app['platform']) : null),
                'real_device_browser' => $app['version'] ? 'Castl-it POS '.$app['version'] : 'Castl-it POS',
                'real_device_ip' => $request->ip(),
                'real_device_user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
                'app_version' => $app['version'],
                'app_install_id' => $app['install'],
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
