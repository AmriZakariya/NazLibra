<?php

namespace App\Http\Middleware;

use App\Models\Role;
use App\Models\Tenant;
use App\Support\AppModules;
use App\Support\Permissions;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $tenant = TenantContext::resolve($request, $user);

        if (! $user || ! $tenant || ! $user->tenants()->whereKey($tenant->id)->exists()) {
            return $this->deny($request, $tenant);
        }

        $module = $this->moduleForRoute($request);
        if ($module !== null && ! AppModules::enabled($tenant, $module)) {
            abort(404);
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
        $roleKey    = $tenantUser?->pivot?->role ?? '';

        if (empty($roleKey)) {
            return [];
        }

        // Owner always has full access — no DB lookup needed.
        if ($roleKey === 'owner') {
            return ['*'];
        }

        $rolePermissions = Role::where('tenant_id', $tenant->id)
            ->where('key', $roleKey)
            ->value('permissions') ?? [];

        $rolePermissions = is_string($rolePermissions)
            ? (json_decode($rolePermissions, true) ?: [])
            : (is_array($rolePermissions) ? $rolePermissions : []);

        return array_values($rolePermissions);
    }

    private function moduleForRoute(Request $request): ?string
    {
        return AppModules::keyForRouteName(
            (string) $request->route()?->getName(),
            $request->route('module') ? (string) $request->route('module') : null,
            (string) $request->query('section', 'list'),
        );
    }

    private function allows(array $permissions, string $required): bool
    {
        return Permissions::allows($permissions, $required);
    }

    private function requiredPermission(Request $request): ?string
    {
        $name = (string) $request->route()?->getName();

        // The module screens carry their section in the query string, so they
        // cannot be a flat route lookup.
        if ($name === 'module') {
            return $this->modulePermission(
                (string) $request->route('module'),
                (string) $request->query('section', 'list'),
            );
        }

        return Permissions::routeMap()[$name] ?? null;
    }

    private function modulePermission(string $module, string $section): ?string
    {
        return match ($module) {
            'invoices' => str_contains($section, 'estimate') ? 'estimates.view' : 'invoices.view',
            'sales' => str_contains($section, 'payment') ? 'sales.payments' : (str_contains($section, 'return') ? 'sales.refund' : 'sales.view'),
            'online-orders' => in_array($section, ['add'], true) ? 'online_orders.create' : 'online_orders.view',
            'cash-register' => 'cash_register.view',
            'purchases' => in_array($section, ['add'], true) ? 'purchases.create' : 'purchases.view',
            'contacts' => in_array($section, ['customer-add', 'supplier-add', 'import-customers', 'import-suppliers'], true) ? 'contacts.create' : 'contacts.view',
            'finance' => in_array($section, ['expense-add', 'advance-add', 'account-add', 'discount-add'], true) ? 'finance.manage' : 'finance.view',
            'reports' => 'reports.view',
            'settings' => $this->settingsPermission($section),
            default => null,
        };
    }

    private function settingsPermission(string $section): string
    {
        return match ($section) {
            'users' => 'settings.users',
            'roles' => 'settings.roles',
            'modules' => 'settings.modules',
            'warehouses', 'stores' => 'settings.stores',
            'devices', 'printers', 'printer-groups' => 'settings.devices',
            'documents' => 'settings.documents',
            'messaging' => 'settings.messaging',
            'pos' => 'settings.pos',
            'audit' => 'settings.audit',
            'taxes', 'units', 'payment-types', 'countries', 'states' => 'settings.references',
            // The company card, the theme and the user's own password screen.
            default => 'settings.company',
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
