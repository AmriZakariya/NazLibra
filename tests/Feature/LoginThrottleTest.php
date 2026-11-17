<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\LoginThrottle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Neither sign-in limited wrong passwords: the activity journal showed a run
 * of « Identifiants incorrects » with nothing in the way of the next one.
 */
class LoginThrottleTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->user = User::first();
    }

    private function appLogin(string $password)
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $this->user->email, 'password' => $password, 'device_name' => 'till-'.Str::uuid(),
        ]);
    }

    public function test_the_app_refuses_a_sixth_wrong_password_with_a_429(): void
    {
        for ($i = 0; $i < LoginThrottle::PER_ACCOUNT; $i++) {
            $this->appLogin('wrong')->assertStatus(422);
        }

        $this->appLogin('wrong')
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('message', LoginThrottle::MESSAGE);

        // Even the right password waits: otherwise the limit only slows a guesser.
        $this->appLogin('password')->assertStatus(429);

        $this->travel(LoginThrottle::DECAY_SECONDS + 1)->seconds();
        $this->appLogin('password')->assertOk();
    }

    public function test_a_successful_sign_in_clears_the_count(): void
    {
        for ($i = 0; $i < LoginThrottle::PER_ACCOUNT - 1; $i++) {
            $this->appLogin('wrong')->assertStatus(422);
        }
        $this->appLogin('password')->assertOk();

        for ($i = 0; $i < LoginThrottle::PER_ACCOUNT - 1; $i++) {
            $this->appLogin('wrong')->assertStatus(422);
        }
        $this->appLogin('password')->assertOk();
    }

    public function test_one_account_locked_does_not_lock_another(): void
    {
        for ($i = 0; $i <= LoginThrottle::PER_ACCOUNT; $i++) {
            $this->appLogin('wrong');
        }
        $this->appLogin('password')->assertStatus(429);

        $other = User::where('id', '!=', $this->user->id)->firstOrFail();
        $this->postJson('/api/v1/auth/login', [
            'email' => $other->email, 'password' => 'password', 'device_name' => 'till-'.Str::uuid(),
        ])->assertOk();
    }

    public function test_the_back_office_sign_in_is_limited_too(): void
    {
        $this->post(route('logout'));

        for ($i = 0; $i < LoginThrottle::PER_ACCOUNT; $i++) {
            $this->post(route('login.store'), ['email' => $this->user->email, 'password' => 'wrong'])
                ->assertSessionHasErrors('email');
        }

        $this->post(route('login.store'), ['email' => $this->user->email, 'password' => 'password'])
            ->assertSessionHasErrors(['email' => LoginThrottle::MESSAGE]);
        $this->assertGuest();
    }
}
