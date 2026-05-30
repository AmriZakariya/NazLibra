<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_be_created_with_role_permissions_and_store_access(): void
    {
        $this->seed();
        $tenant = Tenant::firstOrFail();

        $this->post(route('settings.users.store'), [
            'name' => 'Responsable Stock',
            'email' => 'stock@example.test',
            'phone' => '+212600000000',
            'password' => 'password-test',
            'role' => 'stockist',
            'permissions' => ['items.view', 'stock.adjust'],
            'store_access' => ['Magasin principal', 'Dépôt'],
            'is_active' => '1',
        ])->assertRedirect(route('module', ['module' => 'settings', 'section' => 'users']));

        $user = User::where('email', 'stock@example.test')->firstOrFail();
        $pivot = $tenant->users()->whereKey($user->id)->firstOrFail()->pivot;

        $this->assertSame('stockist', $pivot->role);
        $this->assertSame(['items.view', 'stock.adjust'], json_decode($pivot->permissions, true));
        $this->assertSame(['Magasin principal', 'Dépôt'], json_decode($pivot->store_access, true));
    }

    public function test_role_can_be_created_with_permissions(): void
    {
        $this->seed();

        $this->post(route('settings.roles.store'), [
            'name' => 'Manager caisse',
            'key' => 'manager_caisse',
            'permissions' => ['sales.view', 'sales.create', 'sales.refund'],
        ])->assertRedirect(route('module', ['module' => 'settings', 'section' => 'roles']));

        $this->assertDatabaseHas('roles', [
            'key' => 'manager_caisse',
            'name' => 'Manager caisse',
        ]);
    }

    public function test_store_can_be_created_and_selected_as_current_store(): void
    {
        $this->seed();
        $tenant = Tenant::firstOrFail();

        $this->post(route('settings.stores.store'), [
            'name' => 'Boutique Maarif',
            'type' => 'branch',
            'phone' => '+212522000000',
            'manager' => 'Amina',
            'address' => 'Maarif, Casablanca',
            'is_active' => '1',
        ])->assertRedirect(route('module', ['module' => 'settings', 'section' => 'warehouses']));

        $tenant->refresh();
        $store = collect($tenant->settings['stores'])->firstWhere('name', 'Boutique Maarif');

        $this->assertNotNull($store);

        $this->post(route('settings.current-store.update'), [
            'current_store' => $store['key'],
        ])->assertRedirect();

        $tenant->refresh();
        $this->assertSame($store['key'], $tenant->settings['current_store']);
    }
}
