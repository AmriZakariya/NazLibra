<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
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
            ->assertSee('Se connecter')
            ->assertSee(config('app.version'));
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

    public function test_authenticated_layout_displays_app_version(): void
    {
        $this->seed();

        $user = User::where('email', 'amina@librairie-atlas.ma')->firstOrFail();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(config('app.version'))
            ->assertSee('sidebar-release', false);
    }

    public function test_dashboard_exposes_period_filters_and_reports(): void
    {
        $this->seed();

        $user = User::where('email', 'amina@librairie-atlas.ma')->firstOrFail();

        $this->actingAs($user)
            ->get(route('dashboard', [
                'period' => 'custom',
                'from' => now()->subDays(7)->toDateString(),
                'to' => now()->toDateString(),
            ]))
            ->assertOk()
            ->assertSee('dashboard-filter-panel', false)
            ->assertSee('Heures de pointe')
            ->assertSee('Ventes par catégorie')
            ->assertSee('Dépenses par catégorie')
            ->assertSee('Résultat net estimé')
            ->assertSee('Paiements période');
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
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => User::where('email', 'amina@librairie-atlas.ma')->value('id'),
            'action' => 'logout',
        ]);
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
        Storage::fake('public');
        $user = User::where('email', 'amina@librairie-atlas.ma')->firstOrFail();

        $this->actingAs($user)
            ->put(route('profile.update'), [
                'name' => 'Amina Atlas',
                'email' => 'amina.atlas@example.test',
                'phone' => '+212 600 111 222',
                'avatar_color' => '#0D9488',
                'profile_photo' => UploadedFile::fake()->image('amina.jpg', 320, 320),
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
        $this->assertNotNull($user->profile_photo_path);
        Storage::disk('public')->assertExists($user->profile_photo_path);
        $this->assertTrue(Hash::check('new-password', $user->password));
    }

    public function test_authenticated_user_can_remove_profile_photo(): void
    {
        $this->seed();
        Storage::fake('public');
        $user = User::where('email', 'amina@librairie-atlas.ma')->firstOrFail();
        $user->forceFill([
            'profile_photo_path' => UploadedFile::fake()->image('existing.jpg', 120, 120)->store('users/profile-photos', 'public'),
        ])->save();

        $oldPath = $user->profile_photo_path;

        $this->actingAs($user)
            ->put(route('profile.update'), [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'avatar_color' => $user->avatar_color,
                'remove_profile_photo' => '1',
            ])
            ->assertRedirect();

        $user->refresh();

        $this->assertNull($user->profile_photo_path);
        Storage::disk('public')->assertMissing($oldPath);
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

    public function test_user_can_lock_and_unlock_session_with_pin(): void
    {
        $this->seed();

        $user = User::where('email', 'amina@librairie-atlas.ma')->firstOrFail();
        $user->forceFill(['pin_hash' => Hash::make('1234')])->save();

        $this->actingAs($user)
            ->post(route('session.lock'))
            ->assertRedirect(route('session.locked'));

        $this->get(route('dashboard'))
            ->assertRedirect(route('session.locked'));

        $this->get(route('session.locked'))
            ->assertOk()
            ->assertSee('Session verrouillée')
            ->assertSee('PIN caisse');

        $this->post(route('session.unlock'), ['pin' => '9999'])
            ->assertSessionHasErrors('pin');

        $this->post(route('session.unlock'), ['pin' => '1234'])
            ->assertRedirect(route('dashboard'));

        $this->get(route('dashboard'))
            ->assertOk();
    }

    public function test_forgot_pin_can_unlock_with_password(): void
    {
        $this->seed();

        $user = User::where('email', 'amina@librairie-atlas.ma')->firstOrFail();

        $this->actingAs($user)
            ->post(route('session.lock'))
            ->assertRedirect(route('session.locked'));

        $this->post(route('session.forgot-pin'), ['password' => 'password'])
            ->assertRedirect(route('dashboard'));

        $this->get(route('dashboard'))
            ->assertOk();
    }

    public function test_only_owner_can_set_user_pin(): void
    {
        $this->seed();

        $tenant = Tenant::firstOrFail();
        $owner = User::where('email', 'amina@librairie-atlas.ma')->firstOrFail();
        $cashier = User::where('email', 'caisse@librairie-atlas.ma')->firstOrFail();

        $this->actingAs($owner)
            ->put(route('settings.users.update', $cashier), [
                'name' => $cashier->name,
                'email' => $cashier->email,
                'phone' => $cashier->phone,
                'role' => 'cashier',
                'store_access' => ['Magasin principal'],
                'permissions' => [],
                'is_active' => '1',
                'pin' => '2468',
            ])
            ->assertRedirect();

        $this->assertTrue(Hash::check('2468', $cashier->fresh()->pin_hash));

        $tenant->users()->updateExistingPivot($cashier->id, [
            'role' => 'cashier',
            'permissions' => json_encode(['settings.users']),
            'store_access' => json_encode(['Magasin principal']),
        ]);

        $this->actingAs($cashier)
            ->put(route('settings.users.update', $cashier), [
                'name' => $cashier->name,
                'email' => $cashier->email,
                'phone' => $cashier->phone,
                'role' => 'cashier',
                'store_access' => ['Magasin principal'],
                'permissions' => [],
                'is_active' => '1',
                'pin' => '1357',
            ])
            ->assertForbidden();
    }
}
