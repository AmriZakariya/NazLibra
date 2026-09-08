<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VirtualDevice;
use App\Models\VirtualDeviceSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A terminal is held by one installation at a time, and the claim is released
 * only by that device logging out or by an admin — never by a lapsed
 * heartbeat. Two tablets ringing up sales on one terminal is the failure this
 * prevents.
 */
class VirtualDeviceClaimTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private VirtualDevice $device;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->tenant = Tenant::first();
        $this->user = User::first();

        $settings = $this->tenant->settings ?? [];
        data_set($settings, 'features.virtual_devices', true);
        $this->tenant->update(['settings' => $settings]);

        $this->device = VirtualDevice::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Caisse comptoir',
            'code' => 'POS-01',
            'type' => 'pos',
            'is_active' => true,
        ]);
    }

    private function token(): string
    {
        return $this->user->createToken('test')->plainTextToken;
    }

    private function connect(string $installId, ?string $label = null)
    {
        return $this->withToken($this->token())->postJson(
            '/api/v1/virtual-devices/connect',
            array_filter([
                'virtual_device_id' => $this->device->id,
                'install_id' => $installId,
                'device_label' => $label,
            ]),
        );
    }

    public function test_a_device_can_claim_a_free_terminal(): void
    {
        $this->connect('tablet-a', 'PAX A920')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonStructure(['session_token', 'device']);

        $this->assertDatabaseHas('virtual_device_sessions', [
            'virtual_device_id' => $this->device->id,
            'disconnected_at' => null,
        ]);
    }

    public function test_a_second_device_is_refused_and_told_who_holds_it(): void
    {
        $this->connect('tablet-a', 'PAX A920')->assertOk();

        $response = $this->connect('tablet-b', 'Samsung Tab')
            ->assertStatus(409)
            ->assertJsonPath('ok', false);

        // The message names the holder so staff know who to ask.
        $this->assertStringContainsString($this->user->name, $response->json('message'));
        $this->assertStringContainsString('PAX A920', $response->json('message'));
        $this->assertSame('PAX A920', $response->json('holder.device_label'));
    }

    public function test_the_same_installation_can_reclaim_after_a_restart(): void
    {
        $first = $this->connect('tablet-a')->assertOk();

        // Relaunching the app must not look like a conflict.
        $this->connect('tablet-a')->assertOk();

        $this->assertSame(
            1,
            VirtualDeviceSession::where('virtual_device_id', $this->device->id)
                ->whereNull('disconnected_at')->count(),
            'reclaiming created a second live session',
        );
        $this->assertNotNull($first->json('session_token'));
    }

    public function test_a_silent_device_keeps_its_terminal(): void
    {
        $this->connect('tablet-a')->assertOk();

        // The tablet died: no heartbeat for hours. By design the terminal stays
        // claimed, because a lapsed claim would let two devices sell at once.
        VirtualDeviceSession::where('virtual_device_id', $this->device->id)
            ->update(['last_seen_at' => now()->subHours(6)]);

        $this->connect('tablet-b')->assertStatus(409);
    }

    public function test_logging_out_frees_the_terminal(): void
    {
        $this->connect('tablet-a')->assertOk();

        $this->withToken($this->token())
            ->postJson('/api/v1/virtual-devices/disconnect', ['install_id' => 'tablet-a'])
            ->assertOk()
            ->assertJsonPath('released', true);

        $this->connect('tablet-b')->assertOk();
    }

    public function test_disconnecting_twice_is_harmless(): void
    {
        $this->connect('tablet-a')->assertOk();

        $this->withToken($this->token())
            ->postJson('/api/v1/virtual-devices/disconnect', ['install_id' => 'tablet-a'])
            ->assertOk();

        // Logout must never fail because the terminal was already freed.
        $this->withToken($this->token())
            ->postJson('/api/v1/virtual-devices/disconnect', ['install_id' => 'tablet-a'])
            ->assertOk()
            ->assertJsonPath('released', false);
    }

    public function test_an_admin_release_frees_the_terminal(): void
    {
        $this->connect('tablet-a')->assertOk();

        app(\App\Services\VirtualDeviceSessions::class)
            ->releaseDevice($this->tenant, (int) $this->device->id);

        $this->connect('tablet-b')->assertOk();
        $this->assertDatabaseHas('virtual_device_sessions', [
            'virtual_device_id' => $this->device->id,
            'disconnect_reason' => 'admin_released',
        ]);
    }

    public function test_heartbeat_reports_when_an_admin_took_the_terminal_away(): void
    {
        $this->connect('tablet-a')->assertOk();

        $this->withToken($this->token())
            ->postJson('/api/v1/virtual-devices/heartbeat', ['install_id' => 'tablet-a'])
            ->assertOk()
            ->assertJsonPath('ok', true);

        app(\App\Services\VirtualDeviceSessions::class)
            ->releaseDevice($this->tenant, (int) $this->device->id);

        // The app uses this to send the user back to terminal selection.
        $this->withToken($this->token())
            ->postJson('/api/v1/virtual-devices/heartbeat', ['install_id' => 'tablet-a'])
            ->assertStatus(409)
            ->assertJsonPath('reason', 'released');
    }

    public function test_the_list_marks_occupied_terminals(): void
    {
        $this->connect('tablet-a', 'PAX A920')->assertOk();

        // Seen from another tablet: occupied, and not claimable.
        $this->withToken($this->token())
            ->withHeader('X-Castlit-Install-Id', 'tablet-b')
            ->getJson('/api/v1/virtual-devices')
            ->assertOk()
            ->assertJsonPath('devices.0.is_occupied', true)
            ->assertJsonPath('devices.0.held_by_this_device', false)
            ->assertJsonPath('devices.0.holder.device_label', 'PAX A920');

        // Seen from the holder itself: still offered.
        $this->withToken($this->token())
            ->withHeader('X-Castlit-Install-Id', 'tablet-a')
            ->getJson('/api/v1/virtual-devices')
            ->assertOk()
            ->assertJsonPath('devices.0.is_occupied', false)
            ->assertJsonPath('devices.0.held_by_this_device', true);
    }

    public function test_switching_terminal_releases_the_previous_one(): void
    {
        $other = VirtualDevice::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Caisse terrasse',
            'code' => 'POS-02',
            'type' => 'pos',
            'is_active' => true,
        ]);

        $this->connect('tablet-a')->assertOk();

        $this->withToken($this->token())->postJson('/api/v1/virtual-devices/connect', [
            'virtual_device_id' => $other->id,
            'install_id' => 'tablet-a',
        ])->assertOk();

        // One installation must never hold two terminals at once.
        $this->assertSame(1, VirtualDeviceSession::whereNull('disconnected_at')->count());
        $this->assertDatabaseHas('virtual_device_sessions', [
            'virtual_device_id' => $this->device->id,
            'disconnect_reason' => 'switched_device',
        ]);
    }

    public function test_a_disabled_terminal_cannot_be_claimed(): void
    {
        $this->device->update(['is_active' => false]);

        $this->connect('tablet-a')->assertStatus(409);
    }

    public function test_the_admin_page_names_the_tablet_holding_a_terminal(): void
    {
        $this->connect('tablet-a', 'PAX A920')->assertOk();

        // "mobile · " told an admin nothing about which physical device to go
        // and free, which is the whole point of tracking the installation.
        $this->actingAs($this->user)
            ->get(route('devices.index'))
            ->assertOk()
            ->assertSee('PAX A920');
    }

    public function test_the_admin_page_flags_a_holder_that_has_gone_silent(): void
    {
        $this->connect('tablet-a', 'PAX A920')->assertOk();

        VirtualDeviceSession::where('virtual_device_id', $this->device->id)
            ->update(['last_seen_at' => now()->subHours(2)]);

        // Claims never lapse on their own, so a silent holder blocks the
        // terminal until freed here — the admin needs to see that.
        $this->actingAs($this->user)
            ->get(route('devices.index'))
            ->assertOk()
            ->assertSee('Silencieux depuis');
    }
}
