<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Item;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use App\Support\DemoReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DemoMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_view_demo_maintenance_screen(): void
    {
        $this->seed();

        $this->get(route('module', ['module' => 'settings', 'section' => 'demo-data']))
            ->assertOk()
            ->assertSee('Préparer / nettoyer les données de démonstration')
            ->assertSee('Librairie')
            ->assertSee('Clear all sales')
            ->assertSee('Clear all inventory data')
            ->assertSee('Clear items')
            ->assertSee('Reset demo workspace')
            ->assertSee('name="confirmation"', false);
    }

    public function test_demo_maintenance_screen_adapts_to_restaurant_and_coffee_activity_modes(): void
    {
        $this->seed();

        $tenant = Tenant::firstOrFail();
        $owner = User::where('email', 'amina@librairie-atlas.ma')->firstOrFail();
        $settings = $tenant->settings ?? [];
        data_set($settings, 'company_profile.business_mode', 'restaurant');
        $tenant->update(['settings' => $settings]);

        $this->actingAs($owner->fresh())
            ->get(route('module', ['module' => 'settings', 'section' => 'demo-data']))
            ->assertOk()
            ->assertSee('Restaurant')
            ->assertSee('Menu/plats')
            ->assertSee('Créer 5-8 plats/menus');

        data_set($settings, 'company_profile.business_mode', 'coffee');
        $tenant->update(['settings' => $settings]);

        $this->actingAs($owner->fresh())
            ->get(route('module', ['module' => 'settings', 'section' => 'demo-data']))
            ->assertOk()
            ->assertSee('Café')
            ->assertSee('Boissons/snacks')
            ->assertSee('Créer boissons/snacks');
    }

    public function test_demo_cleanup_requires_owner_and_confirmation(): void
    {
        $this->seed();

        $saleCount = Sale::count();
        $cashier = User::where('email', 'caisse@librairie-atlas.ma')->firstOrFail();

        $this->actingAs($cashier)
            ->post(route('settings.demo-maintenance.run', 'clear_sales'), [
                'confirmation' => 'DEMO',
            ])
            ->assertForbidden();

        $this->assertSame($saleCount, Sale::count());

        $owner = User::where('email', 'amina@librairie-atlas.ma')->firstOrFail();
        $this->actingAs($owner)
            ->post(route('settings.demo-maintenance.run', 'clear_sales'), [
                'confirmation' => 'NOPE',
            ])
            ->assertSessionHasErrors('confirmation');

        $this->assertSame($saleCount, Sale::count());
    }

    public function test_clear_items_resets_demo_catalog_without_removing_tenant_or_users(): void
    {
        $this->seed();

        $tenantCount = Tenant::count();
        $userCount = User::count();

        $this->assertGreaterThan(0, Item::count());

        $this->post(route('settings.demo-maintenance.run', 'clear_items'), [
            'confirmation' => 'DEMO',
        ])->assertRedirect(route('module', ['module' => 'settings', 'section' => 'demo-data']));

        $this->assertSame(0, Item::count());
        $this->assertSame(0, Sale::count());
        $this->assertSame($tenantCount, Tenant::count());
        $this->assertSame($userCount, User::count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'settings.demo_maintenance.clear_items',
            'friendly_action' => 'Nettoyage données démo',
        ]);
    }

    private const RESET_TABLES = [
        'sales' => ['sales', 'sale_payments', 'sale_returns', 'pos_tickets', 'sale_item_consumptions', 'tip_payouts', 'loyalty_point_transactions'],
        'appointments' => ['appointments', 'appointment_blocks', 'queue_entries'],
        'gifts' => ['gift_cards', 'gift_card_transactions', 'service_packages', 'customer_packages', 'customer_package_movements'],
        'stock' => ['stock_movements', 'inventory_layers', 'item_location_stock', 'stock_adjustments', 'stocktakes', 'stock_transfers'],
        'catalog' => ['items', 'item_components', 'item_staff', 'categories', 'brands'],
        'contacts' => ['contacts'],
        'purchases' => ['purchases', 'purchase_payments', 'purchase_returns'],
        'documents' => ['invoices', 'estimates', 'quotations', 'sale_invoices', 'document_sequences'],
        'finance' => ['cash_register_sessions', 'cash_register_movements', 'expenses', 'expense_categories', 'account_transactions', 'coupons', 'discount_rules', 'customer_advances'],
        'online' => ['online_orders'],
        'schedules' => ['staff_schedules', 'schedule_exceptions', 'shop_closures'],
    ];

    private function seedNewModules(Tenant $tenant): void
    {
        $tid = $tenant->id;
        $now = now();
        $itemId = Item::where('tenant_id', $tid)->value('id');
        $second = Item::where('tenant_id', $tid)->where('id', '!=', $itemId)->value('id') ?? $itemId;
        $userId = User::where('email', 'amina@librairie-atlas.ma')->value('id');
        $contactId = Contact::where('tenant_id', $tid)->value('id');
        $saleId = Sale::where('tenant_id', $tid)->value('id');

        DB::table('contacts')->where('id', $contactId)->update(['notes' => 'allergique', 'loyalty_points' => 40]);
        DB::table('appointments')->insert(['tenant_id' => $tid, 'starts_at' => $now, 'item_id' => $itemId, 'contact_id' => $contactId, 'sale_id' => $saleId, 'created_at' => $now, 'updated_at' => $now]);
        $apptId = DB::table('appointments')->where('tenant_id', $tid)->value('id');
        DB::table('appointment_blocks')->insert(['tenant_id' => $tid, 'user_id' => $userId, 'starts_at' => $now, 'duration_minutes' => 30, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('queue_entries')->insert(['tenant_id' => $tid, 'ticket_number' => 1, 'queue_date' => $now->toDateString(), 'arrived_at' => $now, 'appointment_id' => $apptId, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('gift_cards')->insert(['tenant_id' => $tid, 'code' => 'GC-1', 'initial_amount' => 50, 'balance' => 50, 'sale_id' => $saleId, 'created_at' => $now, 'updated_at' => $now]);
        $gc = DB::table('gift_cards')->where('tenant_id', $tid)->value('id');
        DB::table('gift_card_transactions')->insert(['tenant_id' => $tid, 'gift_card_id' => $gc, 'type' => 'issue', 'amount' => 50, 'balance_after' => 50, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('service_packages')->insert(['tenant_id' => $tid, 'name' => 'Pack 5', 'item_id' => $itemId, 'sessions' => 5, 'price' => 100, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('customer_packages')->insert(['tenant_id' => $tid, 'contact_id' => $contactId, 'name' => 'Pack 5', 'item_id' => $itemId, 'sessions_total' => 5, 'sessions_remaining' => 4, 'sale_id' => $saleId, 'created_at' => $now, 'updated_at' => $now]);
        $cp = DB::table('customer_packages')->where('tenant_id', $tid)->value('id');
        DB::table('customer_package_movements')->insert(['tenant_id' => $tid, 'customer_package_id' => $cp, 'type' => 'use', 'sessions' => 1, 'remaining_after' => 4, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('item_components')->insert(['tenant_id' => $tid, 'item_id' => $itemId, 'component_item_id' => $second, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('item_staff')->insert(['tenant_id' => $tid, 'item_id' => $itemId, 'user_id' => $userId, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('tip_payouts')->insert(['tenant_id' => $tid, 'user_id' => $userId, 'amount' => 5, 'paid_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('loyalty_point_transactions')->insert(['tenant_id' => $tid, 'contact_id' => $contactId, 'type' => 'earn', 'points_amount' => 40, 'idempotency_key' => 'lp-'.$tid, 'recorded_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('staff_schedules')->insert(['tenant_id' => $tid, 'user_id' => $userId, 'weekday' => 1, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('schedule_exceptions')->insert(['tenant_id' => $tid, 'user_id' => $userId, 'date' => $now->toDateString(), 'kind' => 'off', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('shop_closures')->insert(['tenant_id' => $tid, 'starts_on' => $now->toDateString(), 'ends_on' => $now->toDateString(), 'created_at' => $now, 'updated_at' => $now]);
        DB::table('stock_movements')->insert(['tenant_id' => $tid, 'item_id' => $itemId, 'type' => 'adjustment', 'quantity_delta' => 1, 'quantity_after' => 1, 'created_at' => $now, 'updated_at' => $now]);
    }

    private function rows(string $table, int $tid): int
    {
        return DB::table($table)->where('tenant_id', $tid)->count();
    }

    private function allGroups(): array
    {
        return DemoReset::GROUPS;
    }

    public function test_full_reset_empties_every_group_and_keeps_access_data(): void
    {
        $this->seed();
        $tenant = Tenant::firstOrFail();
        $this->seedNewModules($tenant);

        $other = Tenant::create(['name' => 'Autre', 'slug' => 'autre']);
        DB::table('contacts')->insert(['tenant_id' => $other->id, 'name' => 'Other', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('shop_closures')->insert(['tenant_id' => $other->id, 'starts_on' => '2026-01-01', 'ends_on' => '2026-01-02', 'created_at' => now(), 'updated_at' => now()]);

        $kept = [];
        foreach (['roles', 'locations', 'virtual_devices', 'tenant_user'] as $t) {
            $kept[$t] = $this->rows($t, $tenant->id);
        }
        $users = User::count();
        $settings = $tenant->fresh()->settings;

        foreach (self::RESET_TABLES as $tables) {
            foreach ($tables as $t) {
                if (in_array($t, ['sales', 'items', 'contacts', 'appointments', 'stock_movements'], true)) {
                    $this->assertGreaterThan(0, $this->rows($t, $tenant->id), $t);
                }
            }
        }

        $this->post(route('settings.demo-maintenance.run', 'reset_demo'), [
            'confirmation' => 'DEMO', 'hard_delete' => '1', 'groups' => $this->allGroups(),
        ])->assertRedirect();

        foreach (self::RESET_TABLES as $tables) {
            foreach ($tables as $t) {
                $this->assertSame(0, $this->rows($t, $tenant->id), $t.' not empty');
            }
        }
        foreach ($kept as $t => $n) {
            $this->assertSame($n, $this->rows($t, $tenant->id), $t);
        }
        $this->assertSame($users, User::count());
        $this->assertSame($settings, $tenant->fresh()->settings);
        $this->assertSame(1, $this->rows('contacts', $other->id));
        $this->assertSame(1, $this->rows('shop_closures', $other->id));

        $owner = User::where('email', 'amina@librairie-atlas.ma')->firstOrFail();
        $this->assertNotEmpty($owner->password);
        $this->assertTrue(Hash::isHashed($owner->password));
    }

    public function test_default_reset_soft_deletes_and_models_see_nothing(): void
    {
        $this->seed();
        $tenant = Tenant::firstOrFail();
        $this->seedNewModules($tenant);

        $this->post(route('settings.demo-maintenance.run', 'reset_demo'), [
            'confirmation' => 'DEMO', 'groups' => $this->allGroups(),
        ])->assertRedirect();

        $this->assertSame(0, Item::count());
        $this->assertSame(0, Sale::count());
        $this->assertSame(0, Contact::count());
        $this->assertSame(0, $this->rows('appointments', $tenant->id));
        $this->assertSame(0, $this->rows('gift_cards', $tenant->id));
    }

    public function test_an_unchecked_group_is_kept(): void
    {
        $this->seed();
        $tenant = Tenant::firstOrFail();
        $this->seedNewModules($tenant);
        $sales = Sale::count();

        $this->post(route('settings.demo-maintenance.run', 'reset_demo'), [
            'confirmation' => 'DEMO', 'groups' => ['appointments', 'gifts'],
        ])->assertRedirect();

        $this->assertSame(0, $this->rows('appointments', $tenant->id));
        $this->assertSame(0, $this->rows('queue_entries', $tenant->id));
        $this->assertSame(0, $this->rows('gift_cards', $tenant->id));
        $this->assertSame($sales, Sale::count());
        $this->assertGreaterThan(0, $this->rows('shop_closures', $tenant->id));
        $this->assertGreaterThan(0, Item::count());
    }

    public function test_dependencies_are_expanded_server_side(): void
    {
        $expanded = DemoReset::expand(['contacts']);
        foreach (['sales', 'appointments', 'gifts', 'purchases', 'documents', 'online'] as $g) {
            $this->assertContains($g, $expanded);
        }
        $this->assertNotContains('schedules', $expanded);
        $this->assertNotContains('catalog', $expanded);
        $this->assertContains('stock', DemoReset::expand(['catalog']));
        $this->assertSame(['schedules'], DemoReset::expand(['schedules', 'bogus']));

        $this->seed();
        $tenant = Tenant::firstOrFail();
        $this->seedNewModules($tenant);
        $this->post(route('settings.demo-maintenance.run', 'reset_demo'), [
            'confirmation' => 'DEMO', 'hard_delete' => '1', 'groups' => ['catalog'],
        ])->assertRedirect();

        $this->assertSame(0, $this->rows('items', $tenant->id));
        $this->assertSame(0, $this->rows('sales', $tenant->id));
        $this->assertSame(0, $this->rows('appointments', $tenant->id));
        $this->assertSame(0, $this->rows('service_packages', $tenant->id));
        $this->assertSame(0, $this->rows('stock_movements', $tenant->id));
        $this->assertGreaterThan(0, $this->rows('contacts', $tenant->id));
        $this->assertGreaterThan(0, $this->rows('shop_closures', $tenant->id));
    }

    public function test_reset_requires_at_least_one_group_and_owner(): void
    {
        $this->seed();
        $this->post(route('settings.demo-maintenance.run', 'reset_demo'), ['confirmation' => 'DEMO'])
            ->assertSessionHasErrors('groups');

        $cashier = User::where('email', 'caisse@librairie-atlas.ma')->firstOrFail();
        $this->actingAs($cashier)
            ->post(route('settings.demo-maintenance.run', 'reset_demo'), ['confirmation' => 'DEMO', 'groups' => ['sales']])
            ->assertForbidden();
    }
}
