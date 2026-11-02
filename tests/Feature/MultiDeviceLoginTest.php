<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Two tablets sign in as the same cashier and must both stay signed in.
 *
 * login() revokes every existing token sharing the device_name it is given
 * ("to avoid token accumulation"). The app used to send a hardcoded
 * "NazPOS Mobile" from every device, so each login silently killed the other
 * tablets' tokens; they 401'd on their next request and looked randomly
 * disconnected mid-service.
 */
class MultiDeviceLoginTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->tenant = Tenant::first();
        $this->user = User::first();
        $this->user->update(['password' => bcrypt('secret-pass')]);
    }

    private function login(string $deviceName, string $deviceUuid): string
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $this->user->email,
            'password' => 'secret-pass',
            'device_name' => $deviceName,
            'device_uuid' => $deviceUuid,
            'tenant_slug' => $this->tenant->slug,
        ])->assertOk();

        return $response->json('token');
    }

    public function test_two_devices_with_distinct_names_keep_separate_tokens(): void
    {
        $this->login('NazPOS Mobile · AAAAAA', 'uuid-a');
        $this->login('NazPOS Mobile · BBBBBB', 'uuid-b');

        // Asserted on the token rows, not by calling an authenticated endpoint:
        // postJson('/auth/login') leaves a session in the test client and
        // Sanctum's guard falls back to session auth, so /auth/me answers 200
        // even for a token that no longer exists. Production sends no cookie,
        // which is why a revoked tablet really does get 401.
        $this->assertSame(1, $this->tokenCount('NazPOS Mobile · AAAAAA'));
        $this->assertSame(1, $this->tokenCount('NazPOS Mobile · BBBBBB'));
    }

    public function test_a_shared_device_name_destroys_the_other_devices_token(): void
    {
        $this->login('NazPOS Mobile', 'uuid-a');
        $firstTokenId = PersonalAccessToken::latest('id')->first()->id;

        // Second tablet, same name — exactly what the app used to send.
        $this->login('NazPOS Mobile', 'uuid-b');

        // The first tablet's credential is gone; on its next request it 401s
        // and looks randomly disconnected mid-service.
        $this->assertNull(PersonalAccessToken::find($firstTokenId));
        $this->assertSame(1, $this->tokenCount('NazPOS Mobile'));
    }

    public function test_signing_in_twice_from_one_device_does_not_accumulate_tokens(): void
    {
        $this->login('NazPOS Mobile · AAAAAA', 'uuid-a');
        $this->login('NazPOS Mobile · AAAAAA', 'uuid-a');

        // The server's clean-up still does its job within one installation —
        // which is the whole point of scoping the name per device rather than
        // dropping the revocation.
        $this->assertSame(1, $this->tokenCount('NazPOS Mobile · AAAAAA'));
    }

    public function test_logging_in_adds_no_terminal_to_the_shop(): void
    {
        $before = \App\Models\VirtualDevice::where('tenant_id', $this->tenant->id)->count();

        $this->login('NazPOS Mobile · AAAAAA', 'uuid-a');
        $this->login('NazPOS Mobile · BBBBBB', 'uuid-b');

        // Each login used to add a terminal named after the phone, so the
        // shop's list grew with every reinstall. Which tablet holds which
        // terminal is on its claim (device_label), not in extra terminals.
        $this->assertSame($before, \App\Models\VirtualDevice::where('tenant_id', $this->tenant->id)->count());
    }

    private function tokenCount(string $deviceName): int
    {
        return PersonalAccessToken::where('tokenable_id', $this->user->id)
            ->where('name', $deviceName)
            ->count();
    }
}
