<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class PinValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $cashier;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->tenant = Tenant::firstOrFail();
        $this->owner = User::where('email', 'amina@librairie-atlas.ma')->firstOrFail();
        $this->cashier = User::where('email', 'caisse@librairie-atlas.ma')->firstOrFail();
    }

    public function test_web_user_pin_management_accepts_0000_and_other_exact_four_digit_pins(): void
    {
        foreach (['0000', '2468'] as $pin) {
            $this->actingAs($this->owner)->put(route('settings.users.pin', $this->owner), [
                'pin' => $pin,
                'pin_confirmation' => $pin,
            ])->assertRedirect(route('module', ['module' => 'settings', 'section' => 'users']));

            $this->assertTrue(Hash::check($pin, $this->owner->fresh()->pin_hash));
        }
    }

    public function test_web_user_pin_management_rejects_every_non_exact_ascii_four_digit_pin(): void
    {
        foreach (['123', '12345', '12a4', ' 1234', '1234 ', '+123', '-123', '12.3', '١٢٣٤'] as $pin) {
            $this->actingAs($this->owner)->put(route('settings.users.pin', $this->owner), [
                'pin' => $pin,
                'pin_confirmation' => $pin,
            ])->assertSessionHasErrors('pin');
        }
    }

    public function test_web_user_pin_management_rejects_non_string_pin_and_confirmation_mismatch(): void
    {
        $this->actingAs($this->owner)->put(route('settings.users.pin', $this->owner), [
            'pin' => 1234,
            'pin_confirmation' => 1234,
        ])->assertSessionHasErrors('pin');

        $this->actingAs($this->owner)->put(route('settings.users.pin', $this->owner), [
            'pin' => '1234',
            'pin_confirmation' => '4321',
        ])->assertSessionHasErrors('pin');
    }

    public function test_pin_verify_rejects_bad_format_without_revoking_current_token(): void
    {
        $this->cashier->forceFill(['pin_hash' => Hash::make('2468')])->save();
        $token = $this->ownerToken();

        $this->withToken($token)->postJson('/api/v1/auth/pin-verify', [
            'user_id' => $this->cashier->id,
            'pin' => '24680',
        ])->assertUnprocessable()->assertJsonValidationErrors('pin');

        $this->assertNotNull(PersonalAccessToken::findToken($token));
    }

    public function test_pin_verify_switches_operator_with_0000_and_replaces_token(): void
    {
        $this->cashier->forceFill(['pin_hash' => Hash::make('0000')])->save();
        $token = $this->ownerToken();

        $response = $this->withToken($token)->postJson('/api/v1/auth/pin-verify', [
            'user_id' => $this->cashier->id,
            'pin' => '0000',
        ]);

        $response->assertOk()
            ->assertJsonPath('user.id', $this->cashier->id)
            ->assertJsonPath('previous_token_revoked', true)
            ->assertJsonStructure(['token', 'token_type', 'abilities']);

        $this->assertNull(PersonalAccessToken::findToken($token));
        $this->assertSame($this->cashier->id, PersonalAccessToken::findToken($response->json('token'))?->tokenable_id);
    }

    /**
     * An account that never set a PIN was unusable on a shared terminal: the
     * app refused to select it and told the cashier to log in with its
     * password instead. The PIN is a lock on accounts that HAVE one.
     */
    public function test_an_account_with_no_pin_switches_without_one(): void
    {
        $this->cashier->forceFill(['pin_hash' => null])->save();
        $token = $this->ownerToken();

        $response = $this->withToken($token)->postJson('/api/v1/auth/pin-verify', [
            'user_id' => $this->cashier->id,
        ]);

        $response->assertOk()->assertJsonPath('user.id', $this->cashier->id);

        // The token must become the switched-to user's, or the app's
        // actor-consistency check fails on the first sale.
        $this->assertSame(
            $this->cashier->id,
            PersonalAccessToken::findToken($response->json('token'))?->tokenable_id,
        );
        $this->assertNull(PersonalAccessToken::findToken($token));
    }

    public function test_an_account_with_a_pin_still_requires_it(): void
    {
        // The loosening must not become "no account needs a PIN".
        $this->cashier->forceFill(['pin_hash' => Hash::make('2468')])->save();
        $token = $this->ownerToken();

        $this->withToken($token)->postJson('/api/v1/auth/pin-verify', [
            'user_id' => $this->cashier->id,
        ])->assertUnprocessable();

        $this->assertNotNull(PersonalAccessToken::findToken($token),
            'a refused switch must not cost the caller its session');
    }

    public function test_a_wrong_pin_is_still_refused(): void
    {
        $this->cashier->forceFill(['pin_hash' => Hash::make('2468')])->save();
        $token = $this->ownerToken();

        $this->withToken($token)->postJson('/api/v1/auth/pin-verify', [
            'user_id' => $this->cashier->id,
            'pin' => '1357',
        ])->assertUnprocessable();

        $this->assertNotNull(PersonalAccessToken::findToken($token));
    }

    public function test_a_pin_offered_to_an_account_without_one_is_refused(): void
    {
        // Accepting it would answer "does any PIN work for this account?"
        // with yes, which is a probe worth refusing.
        $this->cashier->forceFill(['pin_hash' => null])->save();
        $token = $this->ownerToken();

        $this->withToken($token)->postJson('/api/v1/auth/pin-verify', [
            'user_id' => $this->cashier->id,
            'pin' => '1234',
        ])->assertUnprocessable();

        $this->assertNotNull(PersonalAccessToken::findToken($token));
    }

    public function test_an_inactive_account_cannot_be_switched_to(): void
    {
        $this->cashier->forceFill(['pin_hash' => null, 'is_active' => false])->save();
        $token = $this->ownerToken();

        $this->withToken($token)->postJson('/api/v1/auth/pin-verify', [
            'user_id' => $this->cashier->id,
        ])->assertUnprocessable();
    }

    public function test_an_account_from_another_shop_cannot_be_switched_to(): void
    {
        // The tenant scope is the real boundary now that the PIN is not.
        $outsider = User::factory()->create(['pin_hash' => null]);
        $token = $this->ownerToken();

        $this->withToken($token)->postJson('/api/v1/auth/pin-verify', [
            'user_id' => $outsider->id,
        ])->assertUnprocessable();
    }

    public function test_a_malformed_pin_is_still_rejected_as_a_format_error(): void
    {
        $this->cashier->forceFill(['pin_hash' => null])->save();
        $token = $this->ownerToken();

        $this->withToken($token)->postJson('/api/v1/auth/pin-verify', [
            'user_id' => $this->cashier->id,
            'pin' => '24680',
        ])->assertUnprocessable()->assertJsonValidationErrors('pin');

        $this->assertNotNull(PersonalAccessToken::findToken($token));
    }

    public function test_web_session_unlock_rejects_non_four_digit_pin_before_hash_check(): void
    {
        $this->owner->forceFill(['pin_hash' => Hash::make('12345')])->save();

        $this->actingAs($this->owner)
            ->withSession(['pos_session_locked' => true])
            ->post(route('session.unlock'), ['pin' => '12345'])
            ->assertSessionHasErrors('pin');
    }

    private function ownerToken(): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $this->owner->email,
            'password' => 'password',
            'device_name' => 'pin-test-'.microtime(true),
        ])->assertOk()->json('token');
    }
}
