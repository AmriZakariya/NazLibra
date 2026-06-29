<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
