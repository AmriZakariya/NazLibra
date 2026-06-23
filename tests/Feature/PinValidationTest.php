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

    public function test_api_accepts_0000_and_other_exact_four_digit_pins(): void
    {
        $token = $this->ownerToken();

        foreach (['0000', '2468'] as $pin) {
            $this->withToken($token)->postJson('/api/v1/users/set-pin', [
                'pin' => $pin,
                'pin_confirmation' => $pin,
            ])->assertOk();

            $this->assertTrue(Hash::check($pin, $this->owner->fresh()->pin_hash));
        }
    }

    public function test_api_rejects_every_non_exact_ascii_four_digit_pin(): void
    {
        $token = $this->ownerToken();

        foreach (['123', '12345', '12a4', ' 1234', '1234 ', '+123', '-123', '12.3', '١٢٣٤'] as $pin) {
            $this->withToken($token)->postJson('/api/v1/users/set-pin', [
                'pin' => $pin,
                'pin_confirmation' => $pin,
            ])->assertUnprocessable()
                ->assertJsonValidationErrors('pin');
        }
    }

    public function test_api_rejects_non_string_pin_and_confirmation_mismatch(): void
    {
        $token = $this->ownerToken();

        $this->withToken($token)->postJson('/api/v1/users/set-pin', [
            'pin' => 1234,
            'pin_confirmation' => 1234,
        ])->assertUnprocessable()->assertJsonValidationErrors('pin');

        $this->withToken($token)->postJson('/api/v1/users/set-pin', [
            'pin' => '1234',
            'pin_confirmation' => '4321',
        ])->assertUnprocessable()->assertJsonValidationErrors('pin');
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
