<?php

namespace Tests\Feature;

use App\Support\Permissions;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The permission system, checked against the routes it is supposed to guard.
 *
 * Three lists used to have to agree by hand and did not: a catalogue in the
 * controller, a route map in the middleware, and a third copy in the Flutter
 * app. Thirty-four write routes were gated by nothing at all — the cash
 * register, stocktakes, purchase payments, virtual devices, and setting
 * another user's PIN among them.
 *
 * These are the tests that make that a red build rather than a discovery.
 */
class PermissionsCoverageTest extends TestCase
{
    /** @return list<\Illuminate\Routing\Route> */
    private function tenantWriteRoutes(): array
    {
        $routes = [];
        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();
            if (! $name || str_starts_with($name, 'legacy.')) {
                continue;
            }
            if (! array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE'])) {
                continue;
            }
            if (! in_array('tenant.access', $route->gatherMiddleware(), true)) {
                continue;
            }
            $routes[] = $route;
        }

        return $routes;
    }

    public function test_every_write_route_is_gated_or_deliberately_open(): void
    {
        // The test that stops this rotting again: a new POST route either
        // names the permission it needs or is added to the exempt list on
        // purpose, with a reason beside it.
        $map = Permissions::routeMap();
        $open = Permissions::unguardedRoutes();

        $ungated = [];
        foreach ($this->tenantWriteRoutes() as $route) {
            $name = $route->getName();
            if (! isset($map[$name]) && ! in_array($name, $open, true)) {
                $ungated[] = $name;
            }
        }

        sort($ungated);
        $this->assertSame([], $ungated, "These write routes are open to every user of the tenant:\n  ".implode("\n  ", $ungated));
    }

    public function test_the_money_and_stock_routes_are_gated(): void
    {
        // Named individually because these were the ones actually open, and
        // a regression on any of them costs a shop real money or real stock.
        $map = Permissions::routeMap();

        foreach ([
            'cash-register.open', 'cash-register.close', 'cash-register.movements.store',
            'catalog.stocktakes.store', 'catalog.stocktakes.complete',
            'catalog.stock-transfers.store', 'catalog.stock-transfers.cancel',
            'purchases.payments.store', 'settings.users.pin',
            'settings.demo-maintenance.run', 'devices.store', 'devices.destroy',
        ] as $route) {
            $this->assertArrayHasKey($route, $map, "$route must require a permission");
        }
    }

    public function test_every_permission_a_gate_asks_for_can_be_granted(): void
    {
        // A gate naming a permission missing from the catalogue can only be
        // passed with a wildcard — the role editor cannot offer it.
        $missing = [];
        foreach (Permissions::routeMap() as $route => $permission) {
            if (! Permissions::has($permission)) {
                $missing[$route] = $permission;
            }
        }

        $this->assertSame([], $missing, 'gates asking for permissions nobody can tick: '.json_encode($missing));
    }

    public function test_every_permission_in_the_catalogue_actually_gates_something(): void
    {
        // Five used to gate nothing: ticking them changed nothing at all.
        // Module screens gate through modulePermission(), so those keys are
        // expected here even though no flat route names them.
        $gatedByRoutes = array_values(array_unique(array_values(Permissions::routeMap())));
        $gatedByModuleScreens = [
            'items.view', 'sales.view', 'sales.payments', 'sales.refund',
            'invoices.view', 'estimates.view', 'purchases.view', 'purchases.create',
            'contacts.view', 'contacts.create', 'finance.view', 'finance.manage',
            'online_orders.view', 'online_orders.create', 'reports.view',
            'cash_register.view', 'stock.view', 'settings.audit',
            'settings.company', 'settings.users', 'settings.roles', 'settings.modules',
            'settings.stores', 'settings.devices', 'settings.documents',
            'settings.messaging', 'settings.pos', 'settings.references',
            'sales.discount', 'sales.hold',
        ];

        $useless = array_values(array_diff(
            Permissions::keys(),
            $gatedByRoutes,
            $gatedByModuleScreens,
        ));

        $this->assertSame([], $useless, 'permissions that grant nothing: '.implode(', ', $useless));
    }

    public function test_a_permission_key_belongs_to_exactly_one_group(): void
    {
        $seen = [];
        foreach (Permissions::groups() as $groupKey => $group) {
            foreach (array_keys($group['permissions']) as $key) {
                $this->assertArrayNotHasKey($key, $seen, "$key appears in more than one group, including $groupKey");
                $seen[$key] = $groupKey;
            }
        }

        $this->assertSame(count($seen), count(Permissions::keys()));
    }

    public function test_every_key_is_prefixed_by_something_a_wildcard_can_address(): void
    {
        // Roles are written with `group.*`; a key with no dot could never be
        // granted that way, and one with two could be granted by surprise.
        foreach (Permissions::keys() as $key) {
            $this->assertSame(1, substr_count($key, '.'), "$key should be exactly group.action");
        }
    }

    public function test_the_open_routes_are_the_users_own_session_and_nothing_more(): void
    {
        // The exemption list is the one place a route can hide from the
        // coverage test, so it is pinned.
        $this->assertSame([
            'device.connect',
            'device.disconnect',
            'device.heartbeat',
            'locale.switch',
            'session.forgot-pin',
            'session.lock',
            'session.send-pin-reset',
            'session.unlock',
            'settings.password.update',
        ], collect(Permissions::unguardedRoutes())->sort()->values()->all());
    }

    public function test_wildcards_grant_what_they_say(): void
    {
        $this->assertTrue(Permissions::allows(['*'], 'settings.users'));
        $this->assertTrue(Permissions::allows(['sales.*'], 'sales.refund'));
        $this->assertTrue(Permissions::allows(['sales.refund'], 'sales.refund'));

        $this->assertFalse(Permissions::allows(['sales.*'], 'invoices.view'));
        $this->assertFalse(Permissions::allows(['sales.view'], 'sales.refund'));
        $this->assertFalse(Permissions::allows([], 'sales.view'));
        // A group wildcard must not reach across the dot into another group.
        $this->assertFalse(Permissions::allows(['settings.*'], 'sales.create'));

        // The group is everything before the FIRST dot. Splitting on every
        // dot would make `settings.users.pin` answer to a `settings.users.*`
        // wildcard nobody can grant — which is why keys are held to two
        // segments by the test above.
        $this->assertTrue(Permissions::allows(['settings.*'], 'settings.users.pin'));
        $this->assertFalse(Permissions::allows(['settings.users.*'], 'settings.users.pin'));
    }
}
