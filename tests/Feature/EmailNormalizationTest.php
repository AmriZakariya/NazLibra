<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A new space's owner was refused with the right password: the sign-up kept
 * « Owner.Test@Example.com » and a client install (SQLite) compares emails
 * case-sensitively, so the lowercase address typed at login never matched.
 */
class EmailNormalizationTest extends TestCase
{
    use RefreshDatabase;

    private function provision(string $email): array
    {
        $payload = base64_encode(json_encode([
            'business_name' => 'Salon Test', 'contact_name' => 'Owner Test', 'email' => $email,
            'currency' => 'MAD', 'activity' => 'beauty',
        ]));
        Artisan::call('castlit:install-tenant', ['--payload' => $payload, '--subdomain' => 'salontest']);
        $lines = preg_split('/\r?\n/', trim(Artisan::output()));

        return json_decode(end($lines), true);
    }

    public function test_an_owner_signed_up_with_capitals_logs_in_however_the_address_is_typed(): void
    {
        $result = $this->provision('Owner.Test@Example.com');
        $this->assertSame('owner.test@example.com', $result['owner_email'], 'the access card shows the stored spelling');

        foreach (['owner.test@example.com', 'Owner.Test@Example.com', ' OWNER.TEST@EXAMPLE.COM '] as $typed) {
            auth()->logout();
            $this->post(route('login.store'), ['email' => $typed, 'password' => $result['owner_password']]);
            $this->assertTrue(auth()->check(), "web login as « {$typed} »");
        }
    }

    public function test_the_app_login_ignores_case_too(): void
    {
        $result = $this->provision('Owner.Test@Example.com');

        $this->postJson('/api/v1/auth/login', [
            'email' => 'OWNER.test@example.com', 'password' => $result['owner_password'], 'device_name' => 'till-'.Str::uuid(),
        ])->assertOk();
    }

    public function test_existing_accounts_are_lowercased_unless_two_would_collide(): void
    {
        $id = fn (string $e) => DB::table('users')->insertGetId(['name' => 'x', 'email' => $e, 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
        $mixed = $id('Mixed.Case@Example.com');
        $a = $id('Twin@Example.com');
        $b = $id('twin@example.com');

        $migration = require database_path('migrations/2026_11_23_000001_normalize_user_emails.php');
        $migration->up();

        $this->assertSame('mixed.case@example.com', DB::table('users')->where('id', $mixed)->value('email'));
        $this->assertSame('Twin@Example.com', DB::table('users')->where('id', $a)->value('email'), 'never merged');
        $this->assertSame('twin@example.com', DB::table('users')->where('id', $b)->value('email'));
    }

    public function test_a_user_saved_with_capitals_is_stored_lowercase(): void
    {
        $user = User::factory()->create(['email' => ' New.User@Example.COM']);

        $this->assertSame('new.user@example.com', $user->fresh()->email);
    }
}
