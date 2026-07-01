<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Models\VirtualDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SetupFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_setup_requires_configured_secret(): void
    {
        config(['app.setup_secret' => null]);

        $this->get(route('setup.index'))
            ->assertOk()
            ->assertSee('SETUP_SECRET manquant');

        $this->post(route('setup.secret'), ['secret' => 'anything'])
            ->assertSessionHasErrors('secret');
    }

    public function test_fresh_setup_creates_coffee_tenant_with_activity_defaults(): void
    {
        config(['app.setup_secret' => 'deploy-secret']);

        $this->post(route('setup.secret'), ['secret' => 'deploy-secret'])
            ->assertRedirect(route('setup.store'));

        $this->post(route('setup.store.save'), [
            'name' => 'Coffee Atlas',
            'email' => 'contact@coffee.test',
            'phone' => '+212600000001',
            'address' => 'Agdal, Rabat',
            'currency' => 'mad',
            'timezone' => 'Africa/Casablanca',
            'language' => 'fr',
            'business_mode' => 'coffee',
            'costing_method' => 'lifo',
        ])->assertRedirect(route('setup.owner'));

        $this->post(route('setup.owner.save'), [
            'name' => 'Owner Coffee',
            'email' => 'owner@coffee.test',
            'password' => 'password-secret',
            'password_confirmation' => 'password-secret',
        ])->assertRedirect(route('setup.locations'));

        $this->post(route('setup.locations.save'), [
            'locations' => [
                ['name' => 'Comptoir principal', 'address' => 'Agdal', 'phone' => '+212600000001'],
            ],
        ])->assertRedirect(route('setup.categories'));

        $this->post(route('setup.categories.save'), [
            'categories' => ['Cafés', 'Snacks', 'Cafés', ''],
        ])->assertRedirect(route('setup.devices'));

        $this->get(route('setup.devices'))
            ->assertOk()
            ->assertSee('Appareils virtuels')
            ->assertSee('Caisse Web 1')
            ->assertSee('Mobile POS 3');

        $this->post(route('setup.devices.save'), [
            'enabled' => '1',
            'devices' => [
                ['name' => 'Caisse Web 1', 'code' => 'web-pos-01', 'type' => 'computer', 'description' => 'Terminal navigateur principal', 'is_active' => '1'],
                ['name' => 'Caisse Web 2', 'code' => 'web-pos-02', 'type' => 'computer', 'description' => 'Terminal navigateur secondaire', 'is_active' => '1'],
                ['name' => 'Mobile POS 1', 'code' => 'mobile-pos-01', 'type' => 'mobile', 'description' => 'Terminal mobile 1', 'is_active' => '1'],
                ['name' => 'Mobile POS 2', 'code' => 'mobile-pos-02', 'type' => 'mobile', 'description' => 'Terminal mobile 2', 'is_active' => '1'],
                ['name' => 'Mobile POS 3', 'code' => 'mobile-pos-03', 'type' => 'mobile', 'description' => 'Terminal mobile 3', 'is_active' => '1'],
            ],
        ])->assertRedirect(route('setup.review'));

        $this->post(route('setup.commit'))
            ->assertRedirect(route('setup.done'));

        $tenant = Tenant::firstOrFail();

        $this->assertSame('coffee', $tenant->business_mode);
        $this->assertSame('coffee', $tenant->mode);
        $this->assertSame('coffee', data_get($tenant->settings, 'company_profile.business_mode'));
        $this->assertSame('Menu café & snacks', data_get($tenant->settings, 'catalog.label'));
        $this->assertSame('Boisson / snack', data_get($tenant->settings, 'catalog.type_labels.book'));
        $this->assertTrue(data_get($tenant->settings, 'features.virtual_devices'));
        $this->assertSame('MAD', $tenant->currency);

        $this->assertDatabaseHas('users', ['email' => 'owner@coffee.test', 'current_tenant_id' => $tenant->id]);
        $this->assertDatabaseHas('locations', ['tenant_id' => $tenant->id, 'name' => 'Comptoir principal', 'is_default' => true]);
        $this->assertDatabaseHas('categories', ['tenant_id' => $tenant->id, 'name' => 'Cafés']);
        $this->assertDatabaseHas('categories', ['tenant_id' => $tenant->id, 'name' => 'Snacks']);
        $this->assertSame(2, Category::where('tenant_id', $tenant->id)->count());
        $this->assertDatabaseHas('units', ['tenant_id' => $tenant->id, 'name' => 'Portion']);
        $this->assertDatabaseHas('units', ['tenant_id' => $tenant->id, 'name' => 'Menu']);
        $this->assertSame(4, Unit::where('tenant_id', $tenant->id)->count());
        $this->assertSame(5, VirtualDevice::where('tenant_id', $tenant->id)->count());
        $this->assertSame(2, VirtualDevice::where('tenant_id', $tenant->id)->where('type', 'computer')->count());
        $this->assertSame(3, VirtualDevice::where('tenant_id', $tenant->id)->where('type', 'mobile')->count());

        $owner = User::where('email', 'owner@coffee.test')->firstOrFail();
        $this->assertDatabaseHas('tenant_user', [
            'tenant_id' => $tenant->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);
    }

    public function test_legacy_bookstore_alias_normalizes_to_library(): void
    {
        config(['app.setup_secret' => 'deploy-secret']);

        $this->withSession(['setup_authorized' => true])
            ->post(route('setup.store.save'), [
                'name' => 'Librairie Atlas',
                'currency' => 'MAD',
                'timezone' => 'Africa/Casablanca',
                'language' => 'fr',
                'business_mode' => 'bookstore',
                'costing_method' => 'lifo',
            ])->assertRedirect(route('setup.owner'));

        $this->assertSame('library', session('setup.store.business_mode'));
    }

    public function test_unknown_activity_type_is_rejected(): void
    {
        config(['app.setup_secret' => 'deploy-secret']);

        $this->withSession(['setup_authorized' => true])
            ->post(route('setup.store.save'), [
                'name' => 'Mystery Store',
                'currency' => 'MAD',
                'timezone' => 'Africa/Casablanca',
                'language' => 'fr',
                'business_mode' => 'spaceship',
                'costing_method' => 'lifo',
            ])->assertSessionHasErrors('business_mode');
    }

    public function test_virtual_devices_step_can_disable_module(): void
    {
        config(['app.setup_secret' => 'deploy-secret']);

        $this->withSession(['setup_authorized' => true])
            ->post(route('setup.devices.save'), [
                'enabled' => '0',
                'devices' => [],
            ])->assertRedirect(route('setup.review'));

        $this->assertFalse(session('setup.devices.enabled'));
        $this->assertSame([], session('setup.devices.devices'));
    }

    public function test_setup_remains_available_for_existing_tenant_maintenance(): void
    {
        config(['app.setup_secret' => 'deploy-secret']);

        $tenant = Tenant::create([
            'name' => 'Existing Restaurant',
            'slug' => 'existing-restaurant',
            'mode' => 'restaurant',
            'business_mode' => 'restaurant',
            'currency' => 'MAD',
            'locale' => 'fr_MA',
            'timezone' => 'Africa/Casablanca',
            'settings' => [
                'company_profile' => [
                    'store_name' => 'Existing Restaurant',
                    'business_mode' => 'restaurant',
                    'language_id' => 'fr',
                ],
            ],
        ]);

        $this->assertTrue($tenant->exists);

        $this->get(route('setup.index'))
            ->assertOk()
            ->assertSee('Accès maintenance');

        $this->post(route('setup.secret'), ['secret' => 'deploy-secret'])
            ->assertRedirect(route('setup.store'));

        $this->get(route('setup.store'))
            ->assertOk()
            ->assertSee('Restaurant')
            ->assertSee('Existing Restaurant')
            ->assertSee('Ignorer cette étape');

        $this->get(route('setup.owner'))
            ->assertOk()
            ->assertSee('Changer le mot de passe')
            ->assertSee('Ignorer cette étape')
            ->assertSee('disabled autocomplete=new-password', false);

        $this->post(route('setup.owner.save'), [
            'name' => 'Owner Updated',
            'email' => 'owner-updated@example.test',
        ])->assertRedirect(route('setup.locations'));

        $this->get(route('setup.devices'))
            ->assertOk()
            ->assertSee('Appareils virtuels')
            ->assertSee('Ignorer cette étape');
    }
}
