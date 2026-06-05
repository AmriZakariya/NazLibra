<?php

namespace App\Http\Middleware;

use App\Models\Role;
use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $tenant = $user?->currentTenant ?: Tenant::query()->first();

        if (! $user || ! $tenant || ! $user->tenants()->whereKey($tenant->id)->exists()) {
            return $this->deny($request, $tenant);
        }

        $permissions = $this->permissionsFor($tenant, $user);
        $required = $this->requiredPermission($request);

        if ($required !== null && ! $this->allows($permissions, $required)) {
            return $this->deny($request, $tenant, $required);
        }

        return $next($request);
    }

    private function permissionsFor(Tenant $tenant, $user): array
    {
        $tenantUser = $tenant->users()->whereKey($user->id)->first();
        $pivotPermissions = json_decode((string) ($tenantUser?->pivot?->permissions ?? '[]'), true) ?: [];

        if (in_array('*', $pivotPermissions, true)) {
            return ['*'];
        }

        $rolePermissions = Role::where('tenant_id', $tenant->id)
            ->where('key', (string) ($tenantUser?->pivot?->role ?? ''))
            ->value('permissions') ?? [];
        $rolePermissions = is_string($rolePermissions)
            ? (json_decode($rolePermissions, true) ?: [])
            : $rolePermissions;

        return array_values(array_unique(array_merge(
            is_array($rolePermissions) ? $rolePermissions : [],
            $pivotPermissions,
        )));
    }

    private function allows(array $permissions, string $required): bool
    {
        if (in_array('*', $permissions, true) || in_array($required, $permissions, true)) {
            return true;
        }

        [$group] = explode('.', $required, 2);

        return in_array($group.'.*', $permissions, true);
    }

    private function requiredPermission(Request $request): ?string
    {
        $name = (string) $request->route()?->getName();

        if ($name === 'dashboard') {
            return 'dashboard.view';
        }

        if ($name === 'module') {
            return $this->modulePermission((string) $request->route('module'), (string) $request->query('section', 'list'));
        }

        $exact = [
            'catalog' => 'items.view',
            'catalog.data' => 'items.view',
            'catalog.export' => 'items.view',
            'catalog.labels' => 'items.view',
            'catalog.items.store' => 'items.create',
            'catalog.items.update' => 'items.edit',
            'catalog.items.destroy' => 'items.delete',
            'catalog.categories.store' => 'items.edit',
            'catalog.categories.update' => 'items.edit',
            'catalog.categories.destroy' => 'items.delete',
            'catalog.brands.store' => 'items.edit',
            'catalog.brands.update' => 'items.edit',
            'catalog.brands.destroy' => 'items.delete',
            'catalog.units.store' => 'items.edit',
            'catalog.units.update' => 'items.edit',
            'catalog.units.destroy' => 'items.delete',
            'catalog.taxes.store' => 'items.edit',
            'catalog.taxes.update' => 'items.edit',
            'catalog.taxes.destroy' => 'items.delete',
            'catalog.variants.store' => 'items.edit',
            'catalog.import' => 'items.import',
            'catalog.stock-adjustments.store' => 'stock.adjust',
            'catalog.stock-transfers.store' => 'stock.transfer',
            'pos' => 'sales.create',
            'pos.coupons.preview' => 'sales.create',
            'pos.store' => 'sales.create',
            'pos.tickets.store' => 'sales.create',
            'pos.tickets.destroy' => 'sales.create',
            'sales.store' => 'sales.create',
            'sales.update' => 'sales.edit',
            'sales.invoice.store' => 'sales.view',
            'sales.payments.store' => 'sales.payments',
            'sales.refund' => 'sales.refund',
            'sales.destroy' => 'sales.delete',
            'sales.deliveries.store' => 'sales.create',
            'sales.deliveries.update' => 'sales.create',
            'quotations.store' => 'sales.create',
            'quotations.update' => 'sales.create',
            'quotations.convert' => 'sales.create',
            'purchases.store' => 'purchases.create',
            'purchases.receive' => 'purchases.receive',
            'purchases.returns.store' => 'purchases.create',
            'contacts.data' => 'contacts.view',
            'contacts.store' => 'contacts.create',
            'contacts.update' => 'contacts.edit',
            'contacts.destroy' => 'contacts.edit',
            'contacts.import' => 'contacts.create',
            'contacts.import.example' => 'contacts.create',
            'customer-advances.data' => 'finance.view',
            'customer-advances.store' => 'finance.manage',
            'customer-advances.destroy' => 'finance.manage',
            'expenses.store' => 'finance.manage',
            'expenses.categories.store' => 'finance.manage',
            'coupons.store' => 'finance.manage',
            'coupons.update' => 'finance.manage',
            'coupons.destroy' => 'finance.manage',
            'accounts.store' => 'finance.manage',
            'accounts.update' => 'finance.manage',
            'accounts.destroy' => 'finance.manage',
            'accounts.deposits.store' => 'finance.manage',
            'accounts.transfers.store' => 'finance.manage',
            'settings.theme.update' => 'settings.theme',
            'settings.company.update' => 'settings.theme',
            'settings.pos.update' => 'settings.theme',
            'settings.current-store.update' => 'settings.users',
            'settings.stores.store' => 'settings.users',
            'settings.stores.update' => 'settings.users',
            'settings.stores.destroy' => 'settings.users',
            'settings.payment-types.store' => 'settings.theme',
            'settings.payment-types.update' => 'settings.theme',
            'settings.payment-types.destroy' => 'settings.theme',
            'settings.countries.store' => 'settings.theme',
            'settings.countries.update' => 'settings.theme',
            'settings.countries.destroy' => 'settings.theme',
            'settings.states.store' => 'settings.theme',
            'settings.states.update' => 'settings.theme',
            'settings.states.destroy' => 'settings.theme',
            'settings.tax-groups.store' => 'settings.theme',
            'settings.tax-groups.update' => 'settings.theme',
            'settings.tax-groups.destroy' => 'settings.theme',
            'settings.users.store' => 'settings.users',
            'settings.users.update' => 'settings.users',
            'settings.users.destroy' => 'settings.users',
            'settings.roles.store' => 'settings.roles',
            'settings.roles.update' => 'settings.roles',
            'settings.roles.destroy' => 'settings.roles',
        ];

        return $exact[$name] ?? null;
    }

    private function modulePermission(string $module, string $section): ?string
    {
        return match ($module) {
            'sales' => str_contains($section, 'payment') ? 'sales.payments' : (str_contains($section, 'return') ? 'sales.refund' : 'sales.view'),
            'purchases' => in_array($section, ['add'], true) ? 'purchases.create' : 'purchases.view',
            'contacts' => in_array($section, ['customer-add', 'supplier-add', 'import-customers', 'import-suppliers'], true) ? 'contacts.create' : 'contacts.view',
            'finance' => in_array($section, ['expense-add', 'advance-add', 'account-add'], true) ? 'finance.manage' : 'finance.view',
            'reports' => 'reports.view',
            'settings' => $this->settingsPermission($section),
            default => null,
        };
    }

    private function settingsPermission(string $section): string
    {
        return match ($section) {
            'users', 'warehouses' => 'settings.users',
            'roles' => 'settings.roles',
            'theme', 'taxes', 'units', 'payment-types', 'countries', 'states', 'password' => 'settings.theme',
            default => 'settings.theme',
        };
    }

    private function deny(Request $request, ?Tenant $tenant = null, ?string $permission = null): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Accès non autorisé.'], 403);
        }

        return response()->view('errors.no-access', [
            'tenant' => $tenant,
            'permission' => $permission,
        ], 403);
    }
}
