<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\ResetPinNotification;
use App\Rules\FourDigitPin;
use App\Support\TenantContext;
use App\Support\TenantClock;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Yajra\DataTables\Facades\DataTables;

class AuthController extends Controller
{
    public function showLogin(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        $tenant = TenantContext::resolve(request());

        return view('auth.login', [
            'tenant' => $tenant,
            'demoLoginEmail' => app()->environment('production')
                ? null
                : $tenant?->users()->where('users.is_active', true)->orderBy('users.id')->value('email'),
        ]);
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials + ['is_active' => true], $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => 'Identifiants invalides ou compte désactivé.',
            ]);
        }

        $tenant = TenantContext::resolve($request, null, false);
        $user = $request->user();

        if ($tenant && $user && ! $user->tenants()->whereKey($tenant->id)->exists()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw ValidationException::withMessages([
                'email' => 'Ce compte n’a pas accès à ce client.',
            ]);
        }

        if ($tenant && $user && (int) $user->current_tenant_id !== (int) $tenant->id) {
            $user->forceFill(['current_tenant_id' => $tenant->id])->save();
        }

        $request->session()->regenerate();
        $request->session()->forget(['pos_session_locked', 'pos_session_locked_at']);

        return redirect()->intended(route('dashboard'))->with('status', 'Connexion réussie.');
    }

    public function lockSession(Request $request): RedirectResponse
    {
        $request->session()->put('pos_session_locked', true);
        $request->session()->put('pos_session_locked_at', now()->toIso8601String());

        return redirect()->route('session.locked');
    }

    public function lockedScreen(Request $request): View|RedirectResponse
    {
        if (! (bool) $request->session()->get('pos_session_locked', false)) {
            return redirect()->route('dashboard');
        }

        $user = $request->user();
        $tenant = TenantContext::resolve($request, $user);

        $anyUserHasPin = $tenant
            ? $tenant->users()->whereNotNull('users.pin_hash')->exists()
            : false;

        return view('auth.locked', [
            'tenant' => $tenant,
            'user' => $user,
            'lockedAt' => $request->session()->get('pos_session_locked_at'),
            'hasPin' => $anyUserHasPin,
        ]);
    }

    public function unlockSession(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'pin' => ['required', 'string', new FourDigitPin],
        ]);

        $currentUser = $request->user();
        $tenant = TenantContext::require($request, $currentUser);

        // Search all tenant users for a matching PIN
        $matchedUser = $tenant->users()
            ->whereNotNull('users.pin_hash')
            ->get()
            ->first(fn (User $u) => Hash::check($data['pin'], $u->pin_hash));

        if (! $matchedUser) {
            throw ValidationException::withMessages([
                'pin' => 'PIN incorrect.',
            ]);
        }

        // If PIN matches a different user, switch to that user
        if ($matchedUser->id !== ($currentUser?->id ?? null)) {
            Auth::login($matchedUser);
            $request->session()->regenerate();
        }

        $request->session()->forget(['pos_session_locked', 'pos_session_locked_at']);

        return redirect()->intended(route('dashboard'))->with('status', 'Session déverrouillée.');
    }

    public function unlockWithPassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string'],
        ]);

        $user = $request->user();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => 'Mot de passe incorrect.',
            ]);
        }

        $request->session()->forget(['pos_session_locked', 'pos_session_locked_at']);

        return redirect()->route('dashboard')->with('status', 'Session déverrouillée. Demandez au propriétaire de définir ou réinitialiser votre PIN.');
    }

    public function logout(Request $request): RedirectResponse
    {
        $deviceSessionId = $request->session()->get('virtual_device_session_id');
        if ($deviceSessionId) {
            \App\Models\VirtualDeviceSession::where('id', $deviceSessionId)
                ->whereNull('disconnected_at')
                ->update([
                    'disconnected_at' => now(),
                    'disconnect_reason' => 'logout',
                ]);
        }

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'Déconnexion réussie.');
    }

    public function profile(): View
    {
        $user = Auth::user();
        $tenant = TenantContext::require(request(), $user);
        $tenantUser = $tenant->users()->whereKey($user?->id)->first();
        $roleKey = (string) ($tenantUser?->pivot?->role ?? '');

        return view('auth.profile', [
            'tenant' => $tenant,
            'active' => 'settings',
            'user' => $user,
            'roleName' => Role::where('tenant_id', $tenant->id)->where('key', $roleKey)->value('name') ?: ucfirst($roleKey ?: 'Aucun rôle'),
            'roleKey' => $roleKey,
            'isOwner' => $this->isOwner($tenant, $user),
            'recentAuditLogs' => $this->isOwner($tenant, $user)
                ? AuditLog::where('tenant_id', $tenant->id)->with('user')->latest()->take(5)->get()
                : collect(),
        ]);
    }

    public function activity(Request $request): View
    {
        $user = $request->user();
        $tenant = TenantContext::require($request, $user);

        if (! $this->isOwner($tenant, $user)) {
            abort(403, 'Seul le propriétaire peut consulter le journal d’activité.');
        }

        $filters = $request->validate([
            'user_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'method' => ['nullable', 'in:POST,PUT,PATCH,DELETE'],
            'q' => ['nullable', 'string', 'max:120'],
            'device_id' => ['nullable', 'integer'],
        ]);

        $query = AuditLog::query()
            ->where('tenant_id', $tenant->id)
            ->with('user')
            ->latest();

        if (! empty($filters['user_id'])) {
            $query->where('user_id', (int) $filters['user_id']);
        }

        if (! empty($filters['device_id'])) {
            $query->where('virtual_device_id', (int) $filters['device_id']);
        }

        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', \Carbon\Carbon::parse($filters['from'])->startOfDay());
        }

        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', \Carbon\Carbon::parse($filters['to'])->endOfDay());
        }

        if (! empty($filters['method'])) {
            $query->where('properties->method', $filters['method']);
        }

        if (! empty($filters['q'])) {
            $search = trim((string) $filters['q']);
            $query->where(function ($builder) use ($search): void {
                $builder->where('action', 'like', '%'.$search.'%')
                    ->orWhere('friendly_action', 'like', '%'.$search.'%')
                    ->orWhere('subject_type', 'like', '%'.$search.'%')
                    ->orWhere('subject_reference_snapshot', 'like', '%'.$search.'%')
                    ->orWhere('subject_name_snapshot', 'like', '%'.$search.'%')
                    ->orWhere('properties->path', 'like', '%'.$search.'%')
                    ->orWhere('properties->url', 'like', '%'.$search.'%')
                    ->orWhere('device_name_snapshot', 'like', '%'.$search.'%')
                    ->orWhere('device_code_snapshot', 'like', '%'.$search.'%')
                    ->orWhere('real_device_platform', 'like', '%'.$search.'%')
                    ->orWhere('real_device_browser', 'like', '%'.$search.'%')
                    ->orWhere('real_device_ip', 'like', '%'.$search.'%');
            });
        }

        $logs = $query->paginate(20)->withQueryString();

        return view('auth.activity', [
            'tenant' => $tenant,
            'active' => 'settings',
            'user' => $user,
            'logs' => $logs,
            'users' => $tenant->users()->orderBy('name')->get(),
            'devices' => \App\Models\VirtualDevice::where('tenant_id', $tenant->id)->orderBy('name')->get(),
            'filters' => [
                'user_id' => $filters['user_id'] ?? '',
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
                'method' => $filters['method'] ?? '',
                'q' => $filters['q'] ?? '',
                'device_id' => $filters['device_id'] ?? '',
            ],
            'totals' => [
                'all' => AuditLog::where('tenant_id', $tenant->id)->count(),
                'today' => AuditLog::where('tenant_id', $tenant->id)->whereDate('created_at', now()->toDateString())->count(),
                'users' => AuditLog::where('tenant_id', $tenant->id)->whereNotNull('user_id')->distinct('user_id')->count('user_id'),
            ],
            'actionLabels' => $this->actionLabels(),
        ]);
    }

    public function activityData(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenant = TenantContext::require($request, $user);

        if (! $this->isOwner($tenant, $user)) {
            abort(403);
        }

        $filters = $request->validate([
            'user_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'method' => ['nullable', 'in:POST,PUT,PATCH,DELETE'],
            'q' => ['nullable', 'string', 'max:120'],
            'device_id' => ['nullable', 'integer'],
        ]);

        $query = AuditLog::query()->where('tenant_id', $tenant->id)->with('user')->latest();

        if (! empty($filters['user_id'])) {
            $query->where('user_id', (int) $filters['user_id']);
        }
        if (! empty($filters['device_id'])) {
            $query->where('virtual_device_id', (int) $filters['device_id']);
        }
        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', \Carbon\Carbon::parse($filters['from'])->startOfDay());
        }
        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', \Carbon\Carbon::parse($filters['to'])->endOfDay());
        }
        if (! empty($filters['method'])) {
            $query->where('properties->method', $filters['method']);
        }
        if (! empty($filters['q'])) {
            $search = trim((string) $filters['q']);
            $query->where(function ($builder) use ($search): void {
                $builder->where('action', 'like', '%'.$search.'%')
                    ->orWhere('friendly_action', 'like', '%'.$search.'%')
                    ->orWhere('subject_reference_snapshot', 'like', '%'.$search.'%')
                    ->orWhere('subject_name_snapshot', 'like', '%'.$search.'%')
                    ->orWhere('properties->path', 'like', '%'.$search.'%')
                    ->orWhere('properties->url', 'like', '%'.$search.'%')
                    ->orWhere('properties->user_agent', 'like', '%'.$search.'%')
                    ->orWhere('device_name_snapshot', 'like', '%'.$search.'%')
                    ->orWhere('device_code_snapshot', 'like', '%'.$search.'%')
                    ->orWhere('real_device_platform', 'like', '%'.$search.'%')
                    ->orWhere('real_device_browser', 'like', '%'.$search.'%')
                    ->orWhere('real_device_ip', 'like', '%'.$search.'%');
            });
        }

        $actionLabels = $this->actionLabels();

        $subjectNavUrl = function ($log) {
            $type = $log->subject_type;
            $id = $log->subject_id;
            if (! $type || ! $id) {
                return null;
            }
            $map = [
                'App\Models\Sale' => ['module' => 'sales', 'section' => 'list', 'param' => 'detail_sale'],
                'App\Models\SaleReturn' => ['module' => 'sales', 'section' => 'returns', 'param' => 'detail_return'],
                'App\Models\Purchase' => ['module' => 'purchases', 'section' => 'list', 'param' => 'detail_purchase'],
                'App\Models\PurchaseReturn' => ['module' => 'purchases', 'section' => 'returns', 'param' => 'detail_purchase_return'],
                'App\Models\Item' => ['route' => 'catalog', 'query' => ['panel' => 'articles']],
                'App\Models\Contact' => ['module' => 'contacts', 'section' => 'customers'],
                'App\Models\Quotation' => ['module' => 'sales', 'section' => 'quotes', 'param' => 'detail_quote'],
                'App\Models\DeliveryNote' => ['module' => 'sales', 'section' => 'delivery', 'param' => 'detail_delivery'],
                'App\Models\SaleInvoice' => ['module' => 'sales', 'section' => 'invoices', 'param' => 'detail_invoice'],
                'App\Models\StockAdjustment' => ['module' => 'stock', 'section' => 'adjustments', 'param' => 'detail_adjustment'],
                'App\Models\StockTransfer' => ['module' => 'stock', 'section' => 'transfers', 'param' => 'detail_transfer'],
                'App\Models\Expense' => ['module' => 'finance', 'section' => 'expenses', 'param' => 'detail_expense'],
                'App\Models\CustomerAdvance' => ['module' => 'finance', 'section' => 'advances', 'param' => 'detail_advance'],
                'App\Models\Coupon' => ['module' => 'finance', 'section' => 'coupons', 'param' => 'detail_coupon'],
                'App\Models\FinancialAccount' => ['module' => 'finance', 'section' => 'accounts', 'param' => 'detail_account'],
                'App\Models\Transfer' => ['module' => 'finance', 'section' => 'transfers', 'param' => 'detail_transfer'],
                'App\Models\Deposit' => ['module' => 'finance', 'section' => 'deposits', 'param' => 'detail_deposit'],
                'App\Models\Warehouse' => ['module' => 'settings', 'section' => 'warehouses', 'param' => 'detail_warehouse'],
                'App\Models\Role' => ['module' => 'settings', 'section' => 'roles', 'param' => 'detail_role'],
                'App\Models\User' => ['module' => 'settings', 'section' => 'users', 'param' => 'detail_user'],
                'App\Models\Category' => ['route' => 'catalog', 'query' => ['panel' => 'categories']],
            ];
            if (isset($map[$type])) {
                $m = $map[$type];
                if (isset($m['route'])) {
                    return route($m['route'], $m['query'] ?? []);
                }
                return route('module', array_merge(['module' => $m['module'], 'section' => $m['section']], [$m['param'] => $id]));
            }
            return null;
        };

        return DataTables::eloquent($query)
            ->editColumn('created_at', fn (AuditLog $log) => TenantClock::format($log->created_at, $tenant))
            ->editColumn('action', function (AuditLog $log) use ($actionLabels) {
                $friendly = $log->friendly_action ?: ($actionLabels[$log->action] ?? null);
                return view('partials.activity-action-cell', [
                    'log' => $log,
                    'friendlyLabel' => $friendly,
                ])->render();
            })
            ->addColumn('reference', function (AuditLog $log) {
                $ref = $log->subject_reference_snapshot;
                $name = $log->subject_name_snapshot;
                if (! $ref && ! $name) return '';
                return view('partials.activity-reference-cell', [
                    'reference' => $ref,
                    'name' => $name,
                ])->render();
            })
            ->addColumn('device', function (AuditLog $log) {
                if (! $log->hasDeviceInfo()) return '';
                return view('partials.activity-device-cell', [
                    'log' => $log,
                ])->render();
            })
            ->addColumn('user_avatar', fn (AuditLog $log) => '<span class="grid size-8 shrink-0 place-items-center rounded-lg bg-brand/10 text-[11px] font-bold text-brand">'.e(Str::upper(Str::substr($log->user?->name ?? 'S', 0, 2))).'</span>')
            ->addColumn('user_name', fn (AuditLog $log) => e($log->user?->name ?? 'Système'))
            ->addColumn('user_email', fn (AuditLog $log) => e($log->user?->email ?? ''))
            ->addColumn('nav_url', function (AuditLog $log) use ($subjectNavUrl) {
                return $subjectNavUrl($log);
            })
            ->addColumn('action_raw', fn (AuditLog $log) => e($log->action))
            ->addColumn('subject_type_label', fn (AuditLog $log) => $log->subject_type ? class_basename($log->subject_type) : null)
            ->addColumn('subject_id', fn (AuditLog $log) => $log->subject_id)
            ->addColumn('subject_name', fn (AuditLog $log) => $log->subjectName())
            ->addColumn('subject_reference', fn (AuditLog $log) => $log->subjectReference())
            ->addColumn('device_label', fn (AuditLog $log) => $log->deviceLabel())
            ->addColumn('real_device_label', fn (AuditLog $log) => $log->realDeviceLabel())
            ->addColumn('virtual_device_label', fn (AuditLog $log) => $log->device_name_snapshot)
            ->addColumn('virtual_device_code', fn (AuditLog $log) => $log->device_code_snapshot)
            ->addColumn('real_device_platform', fn (AuditLog $log) => $log->real_device_platform)
            ->addColumn('real_device_browser', fn (AuditLog $log) => $log->real_device_browser)
            ->addColumn('real_device_ip', fn (AuditLog $log) => $log->real_device_ip)
            ->addColumn('real_device_user_agent', fn (AuditLog $log) => $log->real_device_user_agent)
            ->addColumn('properties_json', function (AuditLog $log) {
                $props = $log->properties ?? [];
                $payload = $props['payload'] ?? [];
                $routeParams = $props['route_parameters'] ?? [];
                $queryParams = $props['query'] ?? [];
                $headers = $props['headers'] ?? [];
                $method = (string) ($props['method'] ?? '—');
                $encode = fn (mixed $value): string => json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
                $requestSummary = [
                    'method' => $method,
                    'status_code' => $props['status_code'] ?? null,
                    'route' => $props['route'] ?? null,
                    'route_action' => $props['route_action'] ?? null,
                    'path' => $props['path'] ?? null,
                    'url' => $props['url'] ?? null,
                    'timezone' => $props['timezone'] ?? config('app.timezone'),
                    'ip' => $props['ip'] ?? $log->real_device_ip,
                    'referer' => $props['referer'] ?? null,
                    'content_type' => $props['content_type'] ?? null,
                    'accept' => $props['accept'] ?? null,
                    'is_ajax' => $props['is_ajax'] ?? null,
                ];

                return json_encode([
                    'method' => $method,
                    'status_code' => $props['status_code'] ?? '—',
                    'ip' => $props['ip'] ?? '—',
                    'route' => $props['route'] ?? '—',
                    'route_action' => $props['route_action'] ?? '—',
                    'timezone' => $props['timezone'] ?? config('app.timezone'),
                    'path' => $props['path'] ?? '—',
                    'url' => $props['url'] ?? '—',
                    'referer' => $props['referer'] ?? '—',
                    'content_type' => $props['content_type'] ?? '—',
                    'accept' => $props['accept'] ?? '—',
                    'is_ajax' => $props['is_ajax'] ?? false,
                    'user_agent' => $props['user_agent'] ?? '—',
                    'payload_json' => $encode($payload),
                    'route_json' => $encode($routeParams),
                    'query_json' => $encode($queryParams),
                    'headers_json' => $encode($headers),
                    'request_summary_json' => $encode($requestSummary),
                    'full_json' => $encode($props),
                    'has_payload' => ! empty($payload),
                    'has_route_parameters' => ! empty($routeParams),
                    'has_query' => ! empty($queryParams),
                    'has_headers' => ! empty($headers),
                ], JSON_UNESCAPED_UNICODE);
            })
            ->rawColumns(['action', 'reference', 'device', 'user_avatar', 'properties_json', 'action_raw'])
            ->toJson();
    }

    /**
     * Map technical action names to user-friendly labels.
     */
    private function actionLabels(): array
    {
        return [
            'catalog.categories.store' => 'Création catégorie',
            'catalog.categories.update' => 'Modification catégorie',
            'catalog.categories.destroy' => 'Suppression catégorie',
            'catalog.items.store' => 'Création article',
            'catalog.items.update' => 'Modification article',
            'catalog.items.destroy' => 'Suppression article',
            'catalog.labels.print' => 'Impression étiquette',
            'catalog.import' => 'Importation',
            'pos.store' => 'Encaissement',
            'pos.refund' => 'Remboursement',
            'sales.store' => 'Création vente',
            'sales.update' => 'Modification vente',
            'sales.destroy' => 'Suppression vente',
            'contacts.customers.store' => 'Création client',
            'contacts.customers.update' => 'Modification client',
            'contacts.customers.destroy' => 'Suppression client',
            'contacts.suppliers.store' => 'Création fournisseur',
            'contacts.suppliers.update' => 'Modification fournisseur',
            'contacts.suppliers.destroy' => 'Suppression fournisseur',
            'finance.expenses.store' => 'Création dépense',
            'finance.expenses.update' => 'Modification dépense',
            'finance.expenses.destroy' => 'Suppression dépense',
            'finance.expense-categories.store' => 'Création catégorie dépense',
            'finance.expense-categories.update' => 'Modification catégorie dépense',
            'finance.expense-categories.destroy' => 'Suppression catégorie dépense',
            'finance.advances.store' => 'Création avance',
            'finance.advances.update' => 'Modification avance',
            'finance.advances.destroy' => 'Suppression avance',
            'finance.coupons.store' => 'Création coupon',
            'finance.coupons.update' => 'Modification coupon',
            'finance.coupons.destroy' => 'Suppression coupon',
            'finance.accounts.store' => 'Création compte',
            'finance.accounts.update' => 'Modification compte',
            'finance.accounts.destroy' => 'Suppression compte',
            'finance.transfers.store' => 'Création transfert',
            'finance.transfers.update' => 'Modification transfert',
            'finance.transfers.destroy' => 'Suppression transfert',
            'finance.deposits.store' => 'Création dépôt',
            'finance.deposits.update' => 'Modification dépôt',
            'finance.deposits.destroy' => 'Suppression dépôt',
            'purchases.store' => 'Création achat',
            'purchases.update' => 'Modification achat',
            'purchases.destroy' => 'Suppression achat',
            'stock.adjustments.store' => 'Ajustement stock',
            'stock.adjustments.update' => 'Modification ajustement',
            'stock.adjustments.destroy' => 'Suppression ajustement',
            'stock.transfers.store' => 'Transfert stock',
            'stock.transfers.update' => 'Modification transfert',
            'stock.transfers.destroy' => 'Suppression transfert',
            'sales.quotes.store' => 'Création devis',
            'sales.quotes.update' => 'Modification devis',
            'sales.quotes.destroy' => 'Suppression devis',
            'sales.quotes.convert' => 'Conversion devis',
            'sales.delivery.store' => 'Création livraison',
            'sales.delivery.update' => 'Modification livraison',
            'sales.delivery.destroy' => 'Suppression livraison',
            'sales.returns.store' => 'Création retour',
            'sales.returns.update' => 'Modification retour',
            'sales.returns.destroy' => 'Suppression retour',
            'settings.users.store' => 'Création utilisateur',
            'settings.users.update' => 'Modification utilisateur',
            'settings.users.destroy' => 'Suppression utilisateur',
            'settings.roles.store' => 'Création rôle',
            'settings.roles.update' => 'Modification rôle',
            'settings.roles.destroy' => 'Suppression rôle',
            'settings.taxes.store' => 'Création taxe',
            'settings.taxes.update' => 'Modification taxe',
            'settings.taxes.destroy' => 'Suppression taxe',
            'settings.units.store' => 'Création unité',
            'settings.units.update' => 'Modification unité',
            'settings.units.destroy' => 'Suppression unité',
            'settings.payment-types.store' => 'Création type paiement',
            'settings.payment-types.update' => 'Modification type paiement',
            'settings.payment-types.destroy' => 'Suppression type paiement',
            'settings.countries.store' => 'Création pays',
            'settings.countries.update' => 'Modification pays',
            'settings.countries.destroy' => 'Suppression pays',
            'settings.states.store' => 'Création état',
            'settings.states.update' => 'Modification état',
            'settings.states.destroy' => 'Suppression état',
            'settings.warehouses.store' => 'Création magasin',
            'settings.warehouses.update' => 'Modification magasin',
            'settings.warehouses.destroy' => 'Suppression magasin',
            'cash-register.store' => 'Ouverture tiroir caisse',
            'cash-register.update' => 'Clôture tiroir caisse',
            'cash-register.deposits.store' => 'Dépôt caisse',
            'cash-register.withdrawals.store' => 'Retrait caisse',
            'profile.update' => 'Mise à jour profil',
            'settings.company.update' => 'Mise à jour société',
            'settings.store.update' => 'Mise à jour magasin',
            'settings.pos.update' => 'Mise à jour paramètres caisse',
            'settings.theme.update' => 'Mise à jour thème',
            'settings.sms.update' => 'Mise à jour SMS',
            'settings.hardware.update' => 'Mise à jour matériel',
            'session.lock' => 'Verrouillage session',
            'session.unlock' => 'Déverrouillage session',
            'logout' => 'Déconnexion',
            'login' => 'Connexion',
            'messaging.store' => 'Envoi message',
            'messaging.templates.store' => 'Création modèle',
            'messaging.templates.update' => 'Modification modèle',
            'messaging.templates.destroy' => 'Suppression modèle',
            'devices.store' => 'Création appareil',
            'devices.update' => 'Modification appareil',
            'devices.destroy' => 'Suppression appareil',
            'devices.toggle' => 'Activation/désactivation appareil',
            'device.connect' => 'Connexion appareil',
            'device.disconnect' => 'Déconnexion appareil',
        ];
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $user = $request->user();
        $tenant = TenantContext::require($request, $user);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:60'],
            'avatar_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'profile_photo' => ['nullable', 'image', 'max:2048'],
            'remove_profile_photo' => ['nullable', 'boolean'],
            'current_password' => ['nullable', 'required_with:password', 'string'],
            'password' => ['nullable', 'confirmed', 'min:8', 'max:120'],
            'pin' => ['nullable', 'string', new FourDigitPin, 'confirmed'],
            'pin_confirmation' => ['nullable', 'required_with:pin', 'string', new FourDigitPin],
            'clear_pin' => ['nullable', 'boolean'],
        ]);

        if (! empty($data['password']) && ! Hash::check((string) $data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'Le mot de passe actuel est incorrect.',
            ]);
        }

        $user->fill([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'avatar_color' => $data['avatar_color'] ?? $user->avatar_color,
        ]);

        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }

        if ($request->hasFile('profile_photo')) {
            if ($user->profile_photo_path) {
                Storage::disk('public')->delete($user->profile_photo_path);
            }

            $user->profile_photo_path = $request->file('profile_photo')->store('users/profile-photos', 'public');
        } elseif ($request->boolean('remove_profile_photo') && $user->profile_photo_path) {
            Storage::disk('public')->delete($user->profile_photo_path);
            $user->profile_photo_path = null;
        }

        // PIN: only owner can set or clear
        if (! empty($data['pin']) || $request->boolean('clear_pin')) {
            if (! $this->isOwner($tenant, $user)) {
                abort(403, 'Seul le propriétaire peut définir ou réinitialiser un PIN.');
            }

            if ($request->boolean('clear_pin')) {
                $user->pin_hash = null;
            } elseif (! empty($data['pin'])) {
                $this->ensurePinUnique($tenant, $data['pin'], $user);
                $user->pin_hash = Hash::make($data['pin']);
            }
        }

        $user->save();

        return back()->with('status', 'Profil mis à jour.');
    }

    public function showForgotPassword(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        return view('auth.password.forgot', [
            'tenant' => TenantContext::resolve($request),
        ]);
    }

    public function sendPasswordResetLink(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email', 'exists:users,email'],
        ]);

        $user = User::where('email', $request->email)->first();
        $token = Str::random(64);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $request->email],
            ['token' => Hash::make($token), 'created_at' => now()]
        );

        $user->notify(new ResetPasswordNotification($token, $request->email));

        return back()->with('status', 'Un lien de réinitialisation vous a été envoyé par email.');
    }

    public function showResetPassword(Request $request): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        return view('auth.password.reset', [
            'tenant' => TenantContext::resolve($request),
            'token' => $request->token,
            'email' => $request->email,
        ]);
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', 'min:8', 'max:120'],
        ]);

        $record = DB::table('password_reset_tokens')->where('email', $data['email'])->first();

        if (! $record || ! Hash::check($data['token'], $record->token)) {
            return back()->withErrors(['email' => 'Lien de réinitialisation invalide ou expiré.']);
        }

        if (now()->diffInMinutes($record->created_at) > 60) {
            DB::table('password_reset_tokens')->where('email', $data['email'])->delete();

            return back()->withErrors(['email' => 'Ce lien a expiré. Veuillez refaire une demande.']);
        }

        $user = User::where('email', $data['email'])->first();

        if (! $user) {
            return back()->withErrors(['email' => 'Utilisateur introuvable.']);
        }

        $user->password = $data['password'];
        $user->save();

        DB::table('password_reset_tokens')->where('email', $data['email'])->delete();

        return redirect()->route('login')->with('status', 'Mot de passe réinitialisé. Connectez-vous avec votre nouveau mot de passe.');
    }

    public function sendPinResetEmail(Request $request): RedirectResponse
    {
        $user = $request->user();
        $token = Str::random(64);

        DB::table('pin_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            ['token' => Hash::make($token), 'created_at' => now()]
        );

        $user->notify(new ResetPinNotification($token, $user->email));

        return back()->with('status', 'Un lien de réinitialisation du PIN vous a été envoyé par email.');
    }

    public function showResetPin(Request $request): View|RedirectResponse
    {
        return view('auth.pin.reset', [
            'tenant' => TenantContext::resolve($request),
            'token' => $request->token,
            'email' => $request->email,
        ]);
    }

    public function updatePin(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'pin' => ['required', 'string', new FourDigitPin, 'confirmed'],
            'pin_confirmation' => ['required', 'string', new FourDigitPin],
        ]);

        $record = DB::table('pin_reset_tokens')->where('email', $data['email'])->first();

        if (! $record || ! Hash::check($data['token'], $record->token)) {
            return back()->withErrors(['pin' => 'Lien de réinitialisation invalide ou expiré.']);
        }

        if (now()->diffInMinutes($record->created_at) > 60) {
            DB::table('pin_reset_tokens')->where('email', $data['email'])->delete();

            return back()->withErrors(['pin' => 'Ce lien a expiré. Veuillez refaire une demande.']);
        }

        $user = User::where('email', $data['email'])->first();

        if (! $user) {
            return back()->withErrors(['pin' => 'Utilisateur introuvable.']);
        }

        $user->pin_hash = Hash::make($data['pin']);
        $user->save();

        DB::table('pin_reset_tokens')->where('email', $data['email'])->delete();

        return redirect()->route('login')->with('status', 'PIN réinitialisé. Vous pouvez maintenant vous connecter et verrouiller votre session.');
    }

    private function isOwner(Tenant $tenant, mixed $user): bool
    {
        if (! $user) {
            return false;
        }

        $tenantUser = $tenant->users()->whereKey($user->id)->first();

        return (string) ($tenantUser?->pivot?->role ?? '') === 'owner';
    }

    private function ensurePinUnique(Tenant $tenant, string $pin, mixed $excludeUser): void
    {
        $otherUsers = $tenant->users()
            ->where('users.id', '!=', $excludeUser?->id)
            ->whereNotNull('users.pin_hash')
            ->get();

        foreach ($otherUsers as $other) {
            if (Hash::check($pin, $other->pin_hash)) {
                throw ValidationException::withMessages([
                    'pin' => 'Ce PIN est déjà utilisé par un autre utilisateur.',
                ]);
            }
        }
    }
}
