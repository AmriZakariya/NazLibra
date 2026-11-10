<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\User;
use App\Models\VirtualDevice;
use App\Support\ActivityRecorder;
use App\Support\ApiActionContext;
use App\Support\RequestSnapshot;
use App\Support\TenantClock;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * The app's half of the activity journal. Every write a signed-in device
 * makes, whether it worked or not, and every login attempt, are recorded
 * here for all routes: a route used to have to opt in, and 38 never did.
 *
 * The screens the app opened ride along on its requests (X-App-Screens) and
 * are kept as page views, without a request of their own.
 */
class RecordApiActivity
{
    /** Calls the app makes on its own, by route URI. */
    public const NOT_RECORDED = [
        'api/v1/virtual-devices/heartbeat',
        // Recomputed at every change of the cart; the sale itself is recorded.
        'api/v1/loyalty/preview',
    ];

    public const AUTH_URIS = [
        'api/v1/auth/login', 'api/v1/auth/logout', 'api/v1/auth/pin-verify',
        'api/v1/users/set-pin', 'api/v1/users/pin',
    ];

    private const MAX_SCREENS = 50;

    /** What the shop owner reads instead of "POST /api/v1/…". */
    private const LABELS = [
        'POST api/v1/auth/login' => 'Connexion (application)',
        'POST api/v1/auth/logout' => 'Déconnexion (application)',
        'POST api/v1/auth/pin-verify' => 'Vérification du PIN',
        'POST api/v1/users/set-pin' => 'PIN défini',
        'DELETE api/v1/users/pin' => 'PIN supprimé',
        'PUT api/v1/users/{user}' => 'Modification utilisateur',
        'POST api/v1/pos/sales' => 'Vente',
        'POST api/v1/pos/sales/{sale}/returns' => 'Remboursement',
        'POST api/v1/pos/tickets' => 'Ticket en attente enregistré',
        'DELETE api/v1/pos/tickets/{ticket}' => 'Ticket en attente supprimé',
        'PATCH api/v1/pos/tickets/{ticket}/name' => 'Ticket en attente renommé',
        'POST api/v1/sales/{sale}/invoice' => 'Facture créée',
        'POST api/v1/appointments' => 'Création rendez-vous',
        'PATCH api/v1/appointments/{appointment}' => 'Modification rendez-vous',
        'PATCH api/v1/appointments/{appointment}/status' => 'Statut rendez-vous',
        'POST api/v1/cash-register/open' => 'Ouverture de caisse',
        'POST api/v1/cash-register/close' => 'Clôture de caisse',
        'POST api/v1/cash-register/movements' => 'Mouvement de caisse',
        'POST api/v1/inventory/adjustments' => 'Ajustement de stock',
        'POST api/v1/kds/sync' => 'Cuisine (KDS) : mise à jour',
        'POST api/v1/tips/payouts' => 'Versement des pourboires',
        'POST api/v1/contacts' => 'Création client',
        'PUT api/v1/contacts/{contact}' => 'Modification client',
        'DELETE api/v1/contacts/{contact}' => 'Suppression client',
        'POST api/v1/contacts/{contact}/loyalty/adjust' => 'Ajustement points fidélité',
        'POST api/v1/contacts/{contact}/transactions' => 'Opération compte client',
        'PUT api/v1/loyalty/settings' => 'Réglages fidélité',
        'POST api/v1/items' => 'Création article',
        'PUT api/v1/items/{item}' => 'Modification article',
        'POST api/v1/items/{item}/images' => 'Photo article',
        'POST api/v1/online-orders/{order}/convert' => 'Commande en ligne encaissée',
        'PATCH api/v1/online-orders/{order}/status' => 'Statut commande en ligne',
        'POST api/v1/purchases/{purchase}/receive' => 'Réception achat',
        'POST api/v1/printers' => 'Ajout imprimante',
        'PUT api/v1/printers/{id}' => 'Modification imprimante',
        'DELETE api/v1/printers/{id}' => 'Suppression imprimante',
        'POST api/v1/printers/push-config' => 'Imprimantes envoyées au serveur',
        'DELETE api/v1/printers/clear-config' => 'Imprimantes effacées',
        'POST api/v1/printer-groups' => 'Ajout groupe d’imprimantes',
        'PUT api/v1/printer-groups/{id}' => 'Modification groupe d’imprimantes',
        'DELETE api/v1/printer-groups/{id}' => 'Suppression groupe d’imprimantes',
        'POST api/v1/roles' => 'Création rôle',
        'PUT api/v1/roles/{role}' => 'Modification rôle',
        'DELETE api/v1/roles/{role}' => 'Suppression rôle',
        'PUT api/v1/settings/pos' => 'Réglages caisse',
        'POST api/v1/virtual-devices/connect' => 'Terminal choisi',
        'POST api/v1/virtual-devices/disconnect' => 'Terminal libéré',
        'POST api/v1/diagnostics/sync-error' => 'Erreur de synchronisation',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            $uri = (string) $request->route()?->uri();
            $login = $uri === 'api/v1/auth/login';
            $actor = $request->user() ?? ($login ? $this->userByEmail($request) : null);
            // Public calls (signup, access code, support) belong to the master's own journals.
            if ($actor === null && ! $login) {
                return $response;
            }

            $tenant = $request->attributes->get('api_tenant');
            $tenant = $tenant instanceof Tenant ? $tenant : TenantContext::resolve($request, $actor);
            if ($tenant === null) {
                return $response;
            }

            $this->recordScreens($request, $tenant, $actor);

            if (ActivityRecorder::isWrite($request) && ! in_array($uri, self::NOT_RECORDED, true)) {
                $this->record($request, $response, $tenant, $actor, $uri);
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return $response;
    }

    private function record(Request $request, Response $response, Tenant $tenant, ?User $actor, string $uri): void
    {
        $app = RequestSnapshot::appClient($request);
        $route = $request->route();
        $device = $this->virtualDevice($request, $tenant);
        $location = $request->attributes->get('api_location');
        [$outcome, $message, $details] = ActivityRecorder::outcome($request, $response);
        $kind = in_array($uri, self::AUTH_URIS, true) ? ActivityRecorder::AUTH : ActivityRecorder::ACTION;

        ActivityRecorder::write([
            'tenant_id' => $tenant->id,
            'user_id' => $actor?->id,
            'kind' => $kind,
            'outcome' => $outcome,
            'outcome_message' => $message,
            'duration_ms' => ActivityRecorder::duration($request),
            'action' => 'api.'.strtolower($request->method()),
            'friendly_action' => self::LABELS[$request->method().' '.$uri] ?? $request->method().' /'.$request->path(),
            'properties' => [
                'method' => $request->method(),
                'url' => $request->fullUrl(),
                'path' => '/'.$request->path(),
                'route' => $route?->getName() ?: $uri,
                'route_action' => $route?->getActionName(),
                'status_code' => $response->getStatusCode(),
                'timezone' => TenantClock::timezone($tenant),
                'ip' => $request->ip(),
                'content_type' => $request->headers->get('content-type'),
                'accept' => $request->headers->get('accept'),
                'is_ajax' => false,
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 240),
                'location_id' => $location?->id,
                'location' => $location?->name,
                'idempotency_key' => $request->input('idempotency_key'),
                'app_version' => $app['version'],
                'app_platform' => $app['platform'],
                'app_device' => $app['device'],
                'query' => RequestSnapshot::query($request),
                'headers' => RequestSnapshot::headers($request),
                'payload' => RequestSnapshot::payload($request),
                'route_parameters' => RequestSnapshot::routeParameters($request),
                'result' => $message,
            ] + $details,
            'virtual_device_id' => $device?->id,
            'device_name_snapshot' => $device?->name,
            'device_code_snapshot' => $device?->code,
            'real_device_platform' => $app['device'] ?? ($app['platform'] ? ucfirst($app['platform']) : null),
            'real_device_browser' => $app['version'] ? 'Castl-it POS '.$app['version'] : 'Castl-it POS',
            'real_device_ip' => $request->ip(),
            'real_device_user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
            'app_version' => $app['version'],
            'app_install_id' => $app['install'],
        ]);
    }

    /**
     * The screens opened since the app's last request, sent as
     * X-App-Screens: [{"s": "/pos", "t": "2026-11-10T09:00:00Z"}, …],
     * percent-encoded. Kept as page views at the time they were opened.
     */
    private function recordScreens(Request $request, Tenant $tenant, ?User $actor): void
    {
        $raw = (string) $request->header('X-App-Screens', '');
        if ($raw === '' || $actor === null) {
            return;
        }
        $screens = json_decode(rawurldecode($raw), true);
        if (! is_array($screens)) {
            return;
        }

        $app = RequestSnapshot::appClient($request);
        $device = $this->virtualDevice($request, $tenant);
        $now = now();
        foreach (array_slice($screens, 0, self::MAX_SCREENS) as $screen) {
            $path = is_array($screen) ? mb_substr(trim(strip_tags((string) ($screen['s'] ?? ''))), 0, 120) : '';
            if ($path === '' || $path[0] !== '/') {
                continue;
            }
            try {
                $at = Carbon::parse((string) ($screen['t'] ?? ''))->setTimezone(config('app.timezone'));
            } catch (\Throwable) {
                $at = $now;
            }
            // A clock off by days, or a screen from last week: kept, but not misplaced.
            if ($at->gt($now) || $at->lt($now->copy()->subDays(7))) {
                $at = $now;
            }

            ActivityRecorder::write([
                'tenant_id' => $tenant->id,
                'user_id' => $actor->id,
                'kind' => ActivityRecorder::VIEW,
                'outcome' => ActivityRecorder::OK,
                'action' => 'app.view',
                'friendly_action' => 'Écran · '.self::screenLabel($path),
                'properties' => [
                    'screen' => $path,
                    'path' => $path,
                    'app_version' => $app['version'],
                    'app_platform' => $app['platform'],
                    'app_device' => $app['device'],
                ],
                'virtual_device_id' => $device?->id,
                'device_name_snapshot' => $device?->name,
                'device_code_snapshot' => $device?->code,
                'real_device_platform' => $app['device'],
                'real_device_browser' => $app['version'] ? 'Castl-it POS '.$app['version'] : 'Castl-it POS',
                'real_device_ip' => $request->ip(),
                'app_version' => $app['version'],
                'app_install_id' => $app['install'],
                'created_at' => $at,
                'updated_at' => $at,
            ]);
        }
    }

    /** "/catalogue/item/42" → "Catalogue › Item": ids are not screens. */
    public static function screenLabel(string $path): string
    {
        $parts = array_filter(explode('/', strtok($path, '?') ?: ''), fn ($p) => $p !== ''
            && ! preg_match('/^(\d+|[0-9a-f\-]{16,}|local-[\w\-]+)$/i', $p));
        if ($parts === []) {
            return 'Accueil';
        }

        return implode(' › ', array_map(fn ($p) => ucfirst(str_replace(['-', '_'], ' ', $p)), $parts));
    }

    private function virtualDevice(Request $request, Tenant $tenant): ?VirtualDevice
    {
        $context = $request->attributes->get('api_action_context');
        if ($context instanceof ApiActionContext) {
            return $context->virtualDevice;
        }
        $id = trim((string) $request->header('X-Virtual-Device-Id', ''));

        return ctype_digit($id) ? VirtualDevice::where('tenant_id', $tenant->id)->find((int) $id) : null;
    }

    private function userByEmail(Request $request): ?User
    {
        $email = $request->input('email');

        return is_string($email) && $email !== ''
            ? User::whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))])->first()
            : null;
    }
}
