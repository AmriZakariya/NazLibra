<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_is_available(): void
    {
        $this->seed();

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Connexion')
            ->assertSee('Se connecter');
    }

    public function test_guest_is_redirected_to_login_for_protected_pages(): void
    {
        $this->seed();

        $this->get(route('dashboard'))
            ->assertRedirect(route('login'));
    }

    public function test_authenticated_user_without_permission_sees_no_access_page(): void
    {
        $this->seed();

        $cashier = User::where('email', 'caisse@librairie-atlas.ma')->firstOrFail();

        $this->actingAs($cashier)
            ->get(route('module', ['module' => 'reports']))
            ->assertForbidden()
            ->assertSee('Accès non autorisé')
            ->assertSee('reports.view');
    }

    public function test_authenticated_user_without_tenant_access_sees_no_access_page(): void
    {
        $this->seed();

        $tenant = Tenant::firstOrFail();
        $externalUser = User::factory()->create([
            'current_tenant_id' => $tenant->id,
            'email' => 'outside@example.test',
        ]);

        $this->actingAs($externalUser)
            ->get(route('dashboard'))
            ->assertForbidden()
            ->assertSee('Accès non autorisé');
    }

    public function test_active_user_can_login_and_logout(): void
    {
        $this->seed();

        $this->post(route('login.store'), [
            'email' => 'amina@librairie-atlas.ma',
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();

        $this->post(route('logout'))->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_inactive_user_cannot_login(): void
    {
        $this->seed();

        User::where('email', 'caisse@librairie-atlas.ma')->update(['is_active' => false]);

        $this->post(route('login.store'), [
            'email' => 'caisse@librairie-atlas.ma',
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_authenticated_user_can_update_profile_and_password(): void
    {
        $this->seed();
        $user = User::where('email', 'amina@librairie-atlas.ma')->firstOrFail();

        $this->actingAs($user)
            ->put(route('profile.update'), [
                'name' => 'Amina Atlas',
                'email' => 'amina.atlas@example.test',
                'phone' => '+212 600 111 222',
                'avatar_color' => '#0D9488',
                'current_password' => 'password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])
            ->assertRedirect();

        $user->refresh();

        $this->assertSame('Amina Atlas', $user->name);
        $this->assertSame('amina.atlas@example.test', $user->email);
        $this->assertSame('+212 600 111 222', $user->phone);
        $this->assertSame('#0D9488', $user->avatar_color);
        $this->assertTrue(Hash::check('new-password', $user->password));
    }

    public function test_profile_and_navbar_show_current_role_name(): void
    {
        $this->seed();
        $user = User::where('email', 'amina@librairie-atlas.ma')->firstOrFail();

        $this->actingAs($user)
            ->get(route('profile'))
            ->assertOk()
            ->assertSee('Owner')
            ->assertSee('Rôle: owner');

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Owner');
    }
}
