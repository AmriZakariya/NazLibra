<?php

namespace Tests\Feature;

use App\Models\User;
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
}
