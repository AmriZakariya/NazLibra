<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\VirtualDevice;
use App\Models\VirtualDeviceSession;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VirtualDevicePersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_connecting_a_web_device_remembers_the_selected_device(): void
    {
        [$user, $tenant, $device] = $this->enabledDeviceFixture();

        $this->actingAs($user)
            ->post(route('device.connect'), ['virtual_device_id' => $device->id])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('virtual_device_session_id')
            ->assertSessionHas('preferred_virtual_device_id', $device->id);

        $this->assertDatabaseHas('virtual_device_sessions', [
            'tenant_id' => $tenant->id,
            'virtual_device_id' => $device->id,
            'user_id' => $user->id,
            'disconnected_at' => null,
        ]);
    }

    public function test_device_selection_screen_preselects_remembered_available_device(): void
    {
        [$user, $tenant, $device] = $this->enabledDeviceFixture();

        $response = $this->actingAs($user)
            ->withSession(['preferred_virtual_device_id' => $device->id])
            ->get(route('device.select'));

        $response->assertOk()
            ->assertSee('data-device-persistence-key="librairepro.virtual_device.'.$tenant->id.'.'.$user->id.'"', false)
            ->assertSee('Dernier appareil')
            ->assertSee('data-device-id="'.$device->id.'"', false);

        $this->assertMatchesRegularExpression(
            '/<input[^>]+name="virtual_device_id"[^>]+value="'.$device->id.'"[^>]+checked/',
            $response->getContent()
        );
    }

    public function test_selected_device_remains_active_even_when_heartbeat_is_old(): void
    {
        [$user, $tenant, $device] = $this->enabledDeviceFixture();

        $session = VirtualDeviceSession::create([
            'tenant_id' => $tenant->id,
            'virtual_device_id' => $device->id,
            'user_id' => $user->id,
            'session_id' => 'browser-session-1',
            'connection_token' => 'token-1',
            'user_agent' => 'PHPUnit Browser',
            'platform' => 'macOS',
            'browser' => 'Safari',
            'ip_address' => '127.0.0.1',
            'connected_at' => now()->subHours(3),
            'last_seen_at' => now()->subHours(2),
        ]);

        $this->actingAs($user)
            ->withSession(['virtual_device_session_id' => $session->id])
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSessionHas('virtual_device_session_id', $session->id);

        $this->assertDatabaseHas('virtual_device_sessions', [
            'id' => $session->id,
            'disconnected_at' => null,
        ]);
    }

    public function test_user_cannot_switch_virtual_device_without_disconnecting_first(): void
    {
        [$user, $tenant, $device] = $this->enabledDeviceFixture();
        $otherDevice = VirtualDevice::create([
            'tenant_id' => $tenant->id,
            'name' => 'Deuxième caisse',
            'code' => 'second-pos',
            'type' => 'computer',
            'is_active' => true,
        ]);

        $session = VirtualDeviceSession::create([
            'tenant_id' => $tenant->id,
            'virtual_device_id' => $device->id,
            'user_id' => $user->id,
            'session_id' => 'browser-session-1',
            'connection_token' => 'token-1',
            'user_agent' => 'PHPUnit Browser',
            'platform' => 'macOS',
            'browser' => 'Safari',
            'ip_address' => '127.0.0.1',
            'connected_at' => now(),
            'last_seen_at' => now(),
        ]);

        $this->actingAs($user)
            ->withSession(['virtual_device_session_id' => $session->id])
            ->post(route('device.connect'), ['virtual_device_id' => $otherDevice->id])
            ->assertSessionHasErrors('virtual_device_id');

        $this->assertDatabaseHas('virtual_device_sessions', [
            'id' => $session->id,
            'virtual_device_id' => $device->id,
            'disconnected_at' => null,
        ]);
    }

    public function test_occupied_device_is_locked_and_shows_real_connection_context(): void
    {
        // Freeze "now" just after the occupant's last heartbeat so it counts as a
        // live session (a device is only locked while its holder is actually
        // connected — see the stale-session regression test below).
        Carbon::setTestNow(Carbon::parse('2026-07-02 10:06:30', 'UTC'));

        [$user, $tenant, $device] = $this->enabledDeviceFixture();
        $otherUser = User::factory()->create(['current_tenant_id' => $tenant->id]);
        $tenant->users()->attach($otherUser->id, ['role' => 'cashier']);

        VirtualDeviceSession::create([
            'tenant_id' => $tenant->id,
            'virtual_device_id' => $device->id,
            'user_id' => $user->id,
            'session_id' => 'browser-session-1',
            'connection_token' => 'token-1',
            'user_agent' => 'Mozilla/5.0 Chrome/120',
            'platform' => 'Windows',
            'browser' => 'Chrome',
            'ip_address' => '10.0.0.8',
            'connected_at' => Carbon::parse('2026-07-02 10:05:00', 'UTC'),
            'last_seen_at' => Carbon::parse('2026-07-02 10:06:00', 'UTC'),
        ]);

        $this->actingAs($otherUser)
            ->get(route('device.select'))
            ->assertOk()
            ->assertSee('Occupé')
            ->assertSee($user->name)
            ->assertSee('Windows')
            ->assertSee('Chrome')
            ->assertSee('10.0.0.8')
            ->assertSee('02/07/2026 11:05')
            ->assertSee('02/07/2026 11:06');

        $this->actingAs($otherUser)
            ->post(route('device.connect'), ['virtual_device_id' => $device->id])
            ->assertSessionHasErrors('virtual_device_id');

        Carbon::setTestNow();
    }

    public function test_stale_session_frees_the_device_and_can_be_reclaimed(): void
    {
        // Regression: a holder whose heartbeat lapsed (closed tab, dropped
        // network, expired cookie) must NOT lock the device forever. It should
        // read as available and be claimable by anyone.
        [$user, $tenant, $device] = $this->enabledDeviceFixture();
        $otherUser = User::factory()->create(['current_tenant_id' => $tenant->id]);
        $tenant->users()->attach($otherUser->id, ['role' => 'cashier']);

        $stale = VirtualDeviceSession::create([
            'tenant_id' => $tenant->id,
            'virtual_device_id' => $device->id,
            'user_id' => $user->id,
            'session_id' => 'ghost-session',
            'connection_token' => 'ghost-token',
            'user_agent' => 'Mozilla/5.0 Chrome/120',
            'platform' => 'Windows',
            'browser' => 'Chrome',
            'ip_address' => '10.0.0.8',
            'connected_at' => now()->subHours(3),
            'last_seen_at' => now()->subMinutes(10), // well past STALE_AFTER_SECONDS
        ]);

        // The selection screen no longer shows it as occupied…
        $this->actingAs($otherUser)
            ->get(route('device.select'))
            ->assertOk()
            ->assertDontSee('Occupé');

        // …the stale row is reaped…
        $this->assertDatabaseHas('virtual_device_sessions', [
            'id' => $stale->id,
            'disconnect_reason' => 'stale',
        ]);

        // …and another user can claim the freed device.
        $this->actingAs($otherUser)
            ->post(route('device.connect'), ['virtual_device_id' => $device->id])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasNoErrors();
    }

    public function test_owner_can_release_locked_virtual_device(): void
    {
        [$user, $tenant, $device] = $this->enabledDeviceFixture();

        $session = VirtualDeviceSession::create([
            'tenant_id' => $tenant->id,
            'virtual_device_id' => $device->id,
            'user_id' => $user->id,
            'session_id' => 'browser-session-1',
            'connection_token' => 'token-1',
            'user_agent' => 'PHPUnit Browser',
            'platform' => 'macOS',
            'browser' => 'Safari',
            'ip_address' => '127.0.0.1',
            'connected_at' => now(),
            'last_seen_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('devices.disconnect', $device))
            ->assertRedirect();

        $this->assertDatabaseHas('virtual_device_sessions', [
            'id' => $session->id,
            'disconnect_reason' => 'admin_released',
        ]);
        $this->assertNotNull($session->fresh()->disconnected_at);
    }

    /**
     * @return array{0: User, 1: Tenant, 2: VirtualDevice}
     */
    private function enabledDeviceFixture(): array
    {
        $this->seed();

        $tenant = Tenant::firstOrFail();
        $user = User::where('current_tenant_id', $tenant->id)->firstOrFail();

        $settings = $tenant->settings ?? [];
        data_set($settings, 'features.virtual_devices', true);
        $tenant->update(['settings' => $settings]);

        $device = VirtualDevice::create([
            'tenant_id' => $tenant->id,
            'name' => 'Caisse web persistée',
            'code' => 'web-persisted-pos',
            'type' => 'computer',
            'is_active' => true,
        ]);

        return [$user, $tenant, $device];
    }
}
