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

            // For store/create actions that don't have route parameters, try to extract subject from response
            if (empty($subject['type']) && $response->isRedirect()) {
                $subject = $this->subjectFromResponse($request, $response) ?: $subject;
            }

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

    private function subjectFromResponse(Request $request, Response $response): ?array
    {
        $action = $request->route()?->getName();
        $redirectUrl = $response->headers->get('Location');

        if (! $redirectUrl) {
            return null;
        }

        // Parse the redirect URL
        $parsedUrl = parse_url($redirectUrl);
        $query = [];
        if (isset($parsedUrl['query'])) {
            parse_str($parsedUrl['query'], $query);
        }

        // For POS store: redirect to /caisse?sale=123
        if ($action === 'pos.store' && isset($query['sale'])) {
            $saleId = (int) $query['sale'];
            if ($saleId > 0) {
                $sale = \App\Models\Sale::whereKey($saleId)->first();
                if ($sale) {
                    return [
                        'type' => \App\Models\Sale::class,
                        'id' => $saleId,
                        'name' => $sale->number ?? null,
                        'reference' => $sale->number ?? null,
                    ];
                }
            }
        }

        // For sales.store: redirect to /modules/sales/list?detail_sale=123
        if ($action === 'sales.store' && isset($query['detail_sale'])) {
            $saleId = (int) $query['detail_sale'];
            if ($saleId > 0) {
                return ['type' => \App\Models\Sale::class, 'id' => $saleId];
            }
        }

        // For purchases.store: redirect to /modules/purchases/list?detail_purchase=123
        if ($action === 'purchases.store' && isset($query['detail_purchase'])) {
            $purchaseId = (int) $query['detail_purchase'];
            if ($purchaseId > 0) {
                return ['type' => \App\Models\Purchase::class, 'id' => $purchaseId];
            }
        }

        // For contacts.customers.store: redirect to /modules/contacts/customers?detail_customer=123
        if ($action === 'contacts.customers.store' && isset($query['detail_customer'])) {
            $contactId = (int) $query['detail_customer'];
            if ($contactId > 0) {
                return ['type' => \App\Models\Contact::class, 'id' => $contactId];
            }
        }

        // For contacts.suppliers.store: redirect to /modules/contacts/suppliers?detail_supplier=123
        if ($action === 'contacts.suppliers.store' && isset($query['detail_supplier'])) {
            $contactId = (int) $query['detail_supplier'];
            if ($contactId > 0) {
                return ['type' => \App\Models\Contact::class, 'id' => $contactId];
            }
        }

        // For catalog.items.store: redirect to /catalogue?panel=articles&detail_item=123
        if ($action === 'catalog.items.store' && isset($query['detail_item'])) {
            $itemId = (int) $query['detail_item'];
            if ($itemId > 0) {
                return ['type' => \App\Models\Item::class, 'id' => $itemId];
            }
        }

        // For catalog.categories.store: redirect to /catalogue?panel=categories&detail_category=123
        if ($action === 'catalog.categories.store' && isset($query['detail_category'])) {
            $categoryId = (int) $query['detail_category'];
            if ($categoryId > 0) {
                return ['type' => \App\Models\Category::class, 'id' => $categoryId];
            }
        }

        // For stock.adjustments.store: redirect to /modules/stock/adjustments?detail_adjustment=123
        if ($action === 'stock.adjustments.store' && isset($query['detail_adjustment'])) {
            $adjustmentId = (int) $query['detail_adjustment'];
            if ($adjustmentId > 0) {
                return ['type' => \App\Models\StockAdjustment::class, 'id' => $adjustmentId];
            }
        }

        // For stock.transfers.store: redirect to /modules/stock/transfers?detail_transfer=123
        if ($action === 'stock.transfers.store' && isset($query['detail_transfer'])) {
            $transferId = (int) $query['detail_transfer'];
            if ($transferId > 0) {
                return ['type' => \App\Models\StockTransfer::class, 'id' => $transferId];
            }
        }

        // For finance.expenses.store: redirect to /modules/finance/expenses?detail_expense=123
        if ($action === 'finance.expenses.store' && isset($query['detail_expense'])) {
            $expenseId = (int) $query['detail_expense'];
            if ($expenseId > 0) {
                return ['type' => \App\Models\Expense::class, 'id' => $expenseId];
            }
        }

        // For finance.advances.store: redirect to /modules/finance/advances?detail_advance=123
        if ($action === 'finance.advances.store' && isset($query['detail_advance'])) {
            $advanceId = (int) $query['detail_advance'];
            if ($advanceId > 0) {
                return ['type' => \App\Models\CustomerAdvance::class, 'id' => $advanceId];
            }
        }

        // For finance.coupons.store: redirect to /modules/finance/coupons?detail_coupon=123
        if ($action === 'finance.coupons.store' && isset($query['detail_coupon'])) {
            $couponId = (int) $query['detail_coupon'];
            if ($couponId > 0) {
                return ['type' => \App\Models\Coupon::class, 'id' => $couponId];
            }
        }

        // For sales.returns.store: redirect to /modules/sales/returns?detail_return=123
        if ($action === 'sales.returns.store' && isset($query['detail_return'])) {
            $returnId = (int) $query['detail_return'];
            if ($returnId > 0) {
                return ['type' => \App\Models\SaleReturn::class, 'id' => $returnId];
            }
        }

        // For purchases.returns.store: redirect to /modules/purchases/returns?detail_purchase_return=123
        if ($action === 'purchases.returns.store' && isset($query['detail_purchase_return'])) {
            $returnId = (int) $query['detail_purchase_return'];
            if ($returnId > 0) {
                return ['type' => \App\Models\PurchaseReturn::class, 'id' => $returnId];
            }
        }

        // For sales.delivery.store: redirect to /modules/sales/delivery?detail_delivery=123
        if ($action === 'sales.delivery.store' && isset($query['detail_delivery'])) {
            $deliveryId = (int) $query['detail_delivery'];
            if ($deliveryId > 0) {
                return ['type' => \App\Models\DeliveryNote::class, 'id' => $deliveryId];
            }
        }

        // For sales.quotes.store: redirect to /modules/sales/quotes?detail_quote=123
        if ($action === 'sales.quotes.store' && isset($query['detail_quote'])) {
            $quoteId = (int) $query['detail_quote'];
            if ($quoteId > 0) {
                return ['type' => \App\Models\Quotation::class, 'id' => $quoteId];
            }
        }

        // For sales.invoices.store: redirect to /modules/sales/invoices?detail_invoice=123
        if ($action === 'sales.invoices.store' && isset($query['detail_invoice'])) {
            $invoiceId = (int) $query['detail_invoice'];
            if ($invoiceId > 0) {
                return ['type' => \App\Models\SaleInvoice::class, 'id' => $invoiceId];
            }
        }

        // For settings.warehouses.store: redirect to /modules/settings/warehouses?detail_warehouse=123
        if ($action === 'settings.warehouses.store' && isset($query['detail_warehouse'])) {
            $warehouseId = (int) $query['detail_warehouse'];
            if ($warehouseId > 0) {
                return ['type' => \App\Models\Warehouse::class, 'id' => $warehouseId];
            }
        }

        // For settings.roles.store: redirect to /modules/settings/roles?detail_role=123
        if ($action === 'settings.roles.store' && isset($query['detail_role'])) {
            $roleId = (int) $query['detail_role'];
            if ($roleId > 0) {
                return ['type' => \App\Models\Role::class, 'id' => $roleId];
            }
        }

        // For settings.users.store: redirect to /modules/settings/users?detail_user=123
        if ($action === 'settings.users.store' && isset($query['detail_user'])) {
            $userId = (int) $query['detail_user'];
            if ($userId > 0) {
                return ['type' => \App\Models\User::class, 'id' => $userId];
            }
        }

        // For finance.accounts.store: redirect to /modules/finance/accounts?detail_account=123
        if ($action === 'finance.accounts.store' && isset($query['detail_account'])) {
            $accountId = (int) $query['detail_account'];
            if ($accountId > 0) {
                return ['type' => \App\Models\FinancialAccount::class, 'id' => $accountId];
            }
        }

        // For finance.transfers.store: redirect to /modules/finance/transfers?detail_transfer=123
        if ($action === 'finance.transfers.store' && isset($query['detail_transfer'])) {
            $transferId = (int) $query['detail_transfer'];
            if ($transferId > 0) {
                return ['type' => \App\Models\Transfer::class, 'id' => $transferId];
            }
        }

        // For finance.deposits.store: redirect to /modules/finance/deposits?detail_deposit=123
        if ($action === 'finance.deposits.store' && isset($query['detail_deposit'])) {
            $depositId = (int) $query['detail_deposit'];
            if ($depositId > 0) {
                return ['type' => \App\Models\Deposit::class, 'id' => $depositId];
            }
        }

        return null;
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

            // If name and reference were already extracted from response
            if (isset($subject['name']) || isset($subject['reference'])) {
                return [
                    'name' => $subject['name'] ?? null,
                    'reference' => $subject['reference'] ?? null,
                ];
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
