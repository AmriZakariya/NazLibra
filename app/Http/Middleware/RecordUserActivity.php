<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class RecordUserActivity
{
    private const RECORDED_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function handle(Request $request, Closure $next): Response
    {
        $actor = $request->user();
        $tenantId = $this->tenantId($request, $actor);
        $response = $next($request);

        if ($this->shouldRecord($request, $response)) {
            $this->record($request, $response, $tenantId, $actor?->id);
        }

        return $response;
    }

    private function shouldRecord(Request $request, Response $response): bool
    {
        $routeName = $request->route()?->getName();

        return in_array($request->method(), self::RECORDED_METHODS, true)
            && $response->getStatusCode() < 500
            && ! $request->is('livewire/*')
            && ! $request->expectsJson()
            && ! $response instanceof \Illuminate\Http\JsonResponse
            && ! in_array($routeName, ['device.heartbeat', 'profile.activity.data'], true);
    }

    private function record(Request $request, Response $response, ?int $tenantId, ?int $userId): void
    {
        try {
            if (! Schema::hasTable('audit_logs')) {
                return;
            }

            $subject = $this->subject($request);
            $device = $this->deviceInfo($request);
            $action = $request->route()?->getName() ?: $request->method().' '.$request->path();
            $snapshots = $this->subjectSnapshots($subject);

            DB::table('audit_logs')->insert([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'action' => $action,
                'friendly_action' => $this->friendlyAction($action),
                'subject_type' => $subject['type'] ?? null,
                'subject_id' => $subject['id'] ?? null,
                'subject_name_snapshot' => $snapshots['name'],
                'subject_reference_snapshot' => $snapshots['reference'],
                'virtual_device_id' => $device['virtual_device_id'],
                'virtual_device_session_id' => $device['virtual_device_session_id'],
                'device_name_snapshot' => $device['device_name_snapshot'],
                'device_code_snapshot' => $device['device_code_snapshot'],
                'real_device_platform' => $device['real_device_platform'],
                'real_device_browser' => $device['real_device_browser'],
                'real_device_ip' => $device['real_device_ip'],
                'real_device_user_agent' => $device['real_device_user_agent'],
                'properties' => json_encode([
                    'method' => $request->method(),
                    'url' => $request->fullUrl(),
                    'path' => $request->path(),
                    'route' => $action,
                    'status_code' => $response->getStatusCode(),
                    'ip' => $request->ip(),
                    'user_agent' => str($request->userAgent() ?? '')->limit(240)->value(),
                    'payload' => $this->sanitizedPayload($request),
                    'route_parameters' => $this->routeParameters($request),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            if (app()->hasDebugModeEnabled()) {
                throw $e;
            }
        }
    }

    private function tenantId(Request $request, mixed $actor = null): ?int
    {
        if ($actor?->current_tenant_id) {
            return (int) $actor->current_tenant_id;
        }

        if ($request->user()?->current_tenant_id) {
            return (int) $request->user()->current_tenant_id;
        }

        return Tenant::query()->value('id');
    }

    private function subject(Request $request): array
    {
        foreach ($request->route()?->parameters() ?? [] as $parameter) {
            if ($parameter instanceof Model) {
                return [
                    'type' => $parameter::class,
                    'id' => $parameter->getKey(),
                ];
            }
        }

        return ['type' => null, 'id' => null];
    }

    private function routeParameters(Request $request): array
    {
        return collect($request->route()?->parameters() ?? [])
            ->mapWithKeys(function ($value, string $key): array {
                if ($value instanceof Model) {
                    return [$key => [
                        'type' => $value::class,
                        'id' => $value->getKey(),
                    ]];
                }

                return [$key => $value];
            })
            ->all();
    }

    private function sanitizedPayload(Request $request): array
    {
        return collect($request->except([
            '_token',
            '_method',
            'password',
            'password_confirmation',
            'current_password',
            'pin',
            'clear_pin',
            'token',
            'api_key',
            'secret',
        ]))
            ->reject(fn ($value) => $value instanceof \Illuminate\Http\UploadedFile)
            ->map(fn ($value) => is_string($value) ? str($value)->limit(500)->value() : $value)
            ->all();
    }

    private function deviceInfo(Request $request): array
    {
        $default = [
            'virtual_device_id' => null,
            'virtual_device_session_id' => null,
            'device_name_snapshot' => null,
            'device_code_snapshot' => null,
            'real_device_platform' => null,
            'real_device_browser' => null,
            'real_device_ip' => null,
            'real_device_user_agent' => null,
        ];

        try {
            $sessionId = $request->session()->get('virtual_device_session_id');

            if (! $sessionId) {
                return $default;
            }

            $deviceSession = DB::table('virtual_device_sessions')
                ->where('id', $sessionId)
                ->whereNull('disconnected_at')
                ->first();

            if (! $deviceSession) {
                return $default;
            }

            $device = DB::table('virtual_devices')
                ->where('id', $deviceSession->virtual_device_id)
                ->first();

            return [
                'virtual_device_id' => $deviceSession->virtual_device_id,
                'virtual_device_session_id' => $deviceSession->id,
                'device_name_snapshot' => $device?->name ?? null,
                'device_code_snapshot' => $device?->code ?? null,
                'real_device_platform' => $deviceSession->platform,
                'real_device_browser' => $deviceSession->browser,
                'real_device_ip' => $deviceSession->ip_address,
                'real_device_user_agent' => $deviceSession->user_agent
                    ? mb_substr($deviceSession->user_agent, 0, 500)
                    : null,
            ];
        } catch (\Throwable) {
            return $default;
        }
    }

    private function friendlyAction(string $action): string
    {
        $labels = [
            'catalog.categories.store' => 'Création catégorie',
            'catalog.categories.update' => 'Modification catégorie',
            'catalog.categories.destroy' => 'Suppression catégorie',
            'catalog.items.store' => 'Création article',
            'catalog.items.update' => 'Modification article',
            'catalog.items.destroy' => 'Suppression article',
            'catalog.labels.print' => 'Impression étiquettes',
            'catalog.import' => 'Importation catalogue',
            'pos.store' => 'Encaissement POS',
            'pos.refund' => 'Remboursement POS',
            'sales.store' => 'Création vente',
            'sales.update' => 'Modification vente',
            'sales.destroy' => 'Suppression vente',
            'sales.quotes.store' => 'Création devis',
            'sales.quotes.update' => 'Modification devis',
            'sales.quotes.destroy' => 'Suppression devis',
            'sales.quotes.convert' => 'Conversion devis en vente',
            'sales.delivery.store' => 'Création livraison',
            'sales.delivery.update' => 'Modification livraison',
            'sales.delivery.destroy' => 'Suppression livraison',
            'sales.returns.store' => 'Création retour vente',
            'sales.returns.update' => 'Modification retour vente',
            'sales.returns.destroy' => 'Suppression retour vente',
            'sales.payments.store' => 'Ajout paiement vente',
            'sales.invoices.store' => 'Création facture',
            'contacts.customers.store' => 'Création client',
            'contacts.customers.update' => 'Modification client',
            'contacts.customers.destroy' => 'Suppression client',
            'contacts.customers.import' => 'Importation clients',
            'contacts.suppliers.store' => 'Création fournisseur',
            'contacts.suppliers.update' => 'Modification fournisseur',
            'contacts.suppliers.destroy' => 'Suppression fournisseur',
            'contacts.suppliers.import' => 'Importation fournisseurs',
            'purchases.store' => 'Création achat',
            'purchases.update' => 'Modification achat',
            'purchases.destroy' => 'Suppression achat',
            'purchases.returns.store' => 'Création retour achat',
            'purchases.returns.update' => 'Modification retour achat',
            'purchases.returns.destroy' => 'Suppression retour achat',
            'stock.adjustments.store' => 'Création ajustement stock',
            'stock.adjustments.update' => 'Modification ajustement stock',
            'stock.adjustments.destroy' => 'Suppression ajustement stock',
            'stock.transfers.store' => 'Création transfert stock',
            'stock.transfers.update' => 'Modification transfert stock',
            'stock.transfers.destroy' => 'Suppression transfert stock',
            'finance.expenses.store' => 'Création dépense',
            'finance.expenses.update' => 'Modification dépense',
            'finance.expenses.destroy' => 'Suppression dépense',
            'finance.advances.store' => 'Création avance client',
            'finance.advances.update' => 'Modification avance client',
            'finance.advances.destroy' => 'Suppression avance client',
            'finance.accounts.store' => 'Création compte',
            'finance.accounts.update' => 'Modification compte',
            'finance.accounts.destroy' => 'Suppression compte',
            'finance.transfers.store' => 'Création transfert argent',
            'finance.deposits.store' => 'Création dépôt',
            'finance.coupons.store' => 'Création coupon',
            'finance.coupons.update' => 'Modification coupon',
            'finance.coupons.destroy' => 'Suppression coupon',
            'finance.expense-categories.store' => 'Création catégorie dépense',
            'finance.expense-categories.update' => 'Modification catégorie dépense',
            'cash-register.open' => 'Ouverture tiroir caisse',
            'cash-register.close' => 'Clôture tiroir caisse',
            'cash-register.deposit' => 'Dépôt caisse',
            'cash-register.withdraw' => 'Retrait caisse',
            'settings.users.store' => 'Création utilisateur',
            'settings.users.update' => 'Modification utilisateur',
            'settings.users.destroy' => 'Suppression utilisateur',
            'settings.roles.store' => 'Création rôle',
            'settings.roles.update' => 'Modification rôle',
            'settings.roles.destroy' => 'Suppression rôle',
            'settings.company.update' => 'Mise à jour société',
            'settings.store.update' => 'Mise à jour magasin',
            'settings.theme.update' => 'Mise à jour thème',
            'settings.hardware.update' => 'Mise à jour matériel',
            'settings.sms.update' => 'Mise à jour SMS',
            'settings.warehouses.store' => 'Création magasin',
            'settings.warehouses.update' => 'Modification magasin',
            'settings.warehouses.destroy' => 'Suppression magasin',
            'profile.update' => 'Mise à jour profil',
            'settings.password.update' => 'Changement mot de passe',
            'session.lock' => 'Verrouillage session',
            'session.unlock' => 'Déverrouillage session',
            'login.store' => 'Connexion',
            'logout' => 'Déconnexion',
            'messaging.store' => 'Envoi message',
            'messaging.templates.store' => 'Création modèle message',
            'messaging.templates.update' => 'Modification modèle message',
            'messaging.templates.destroy' => 'Suppression modèle message',
            'devices.store' => 'Création appareil',
            'devices.update' => 'Modification appareil',
            'devices.destroy' => 'Suppression appareil',
            'devices.toggle' => 'Activation/désactivation appareil',
            'device.connect' => 'Connexion appareil',
            'device.disconnect' => 'Déconnexion appareil',
        ];

        if (isset($labels[$action])) {
            return $labels[$action];
        }

        return $this->generateFriendlyAction($action);
    }

    private function generateFriendlyAction(string $action): string
    {
        if (str_contains($action, '.store')) {
            $entity = str_replace('.store', '', $action);

            return 'Création '.$this->humanizeEntity($entity);
        }
        if (str_contains($action, '.update')) {
            $entity = str_replace('.update', '', $action);

            return 'Modification '.$this->humanizeEntity($entity);
        }
        if (str_contains($action, '.destroy') || str_contains($action, '.delete')) {
            $entity = str_replace(['.destroy', '.delete'], '', $action);

            return 'Suppression '.$this->humanizeEntity($entity);
        }

        return $this->humanizeEntity($action);
    }

    private function humanizeEntity(string $entity): string
    {
        $entity = trim($entity, '. ');

        return str($entity)
            ->replace('.', ' ')
            ->replace('-', ' ')
            ->replace('_', ' ')
            ->headline()
            ->lower()
            ->ucfirst()
            ->value();
    }

    private function subjectSnapshots(array $subject): array
    {
        $default = ['name' => null, 'reference' => null];

        try {
            $type = $subject['type'] ?? null;
            $id = $subject['id'] ?? null;

            if (! $type || ! $id || ! class_exists($type)) {
                return $default;
            }

            $model = $type::query()->find($id);

            if (! $model) {
                return $default;
            }

            $name = $model->name ?? $model->title ?? null;
            $reference = $model->number ?? $model->code ?? null;

            return [
                'name' => $name,
                'reference' => $reference,
            ];
        } catch (\Throwable) {
            return $default;
        }
    }
}
