<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\VirtualDevice;
use App\Models\VirtualDeviceSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Each shop has its web terminals and its mobile ones. A phone used to get a
 * terminal of its own at every login ("NazPOS Mobile · E97ED1"), and the web
 * once sat itself on the owner's phone terminal without asking.
 */
class TerminalSeparationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private VirtualDevice $web;
    private VirtualDevice $mobile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->tenant = Tenant::first();
        $this->user = User::first();
        $settings = $this->tenant->settings ?? [];
        data_set($settings, 'features.virtual_devices', true);
        $this->tenant->update(['settings' => $settings]);

        VirtualDevice::where('tenant_id', $this->tenant->id)->delete();
        $this->web = VirtualDevice::create(['tenant_id' => $this->tenant->id, 'name' => 'Terminal Web 1', 'code' => 'terminal-web-1', 'type' => 'computer', 'is_active' => true]);
        $this->mobile = VirtualDevice::create(['tenant_id' => $this->tenant->id, 'name' => 'Terminal Mobile 2', 'code' => 'terminal-mobile-2', 'type' => 'mobile', 'is_active' => true]);
    }

    public function test_logging_in_on_a_phone_creates_no_terminal(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => $this->user->email, 'password' => 'password',
            'device_name' => 'NazPOS Mobile · E97ED1', 'device_uuid' => (string) Str::uuid(),
        ])->assertOk();

        $this->assertSame(2, VirtualDevice::where('tenant_id', $this->tenant->id)->count());
    }

    public function test_the_phantom_terminals_already_created_are_removed(): void
    {
        $phantom = VirtualDevice::create(['tenant_id' => $this->tenant->id, 'name' => 'NazPOS Mobile · E97ED1',
            'code' => 'e97ed152-a5c7-4b9a-aec1-225cbb3a1150', 'type' => 'mobile', 'is_active' => true]);

        (require database_path('migrations/2026_11_02_000001_remove_terminals_created_by_app_logins.php'))->up();

        $this->assertNull(VirtualDevice::find($phantom->id));
        $this->assertNotNull(VirtualDevice::find($this->mobile->id), 'the shop\'s own terminals stay');
    }

    public function test_the_app_cannot_sit_on_a_web_terminal(): void
    {
        $this->withToken($this->user->createToken('t')->plainTextToken)
            ->postJson('/api/v1/virtual-devices/connect', ['virtual_device_id' => $this->web->id, 'install_id' => 'phone-1'])
            ->assertStatus(422);
    }

    public function test_the_web_cannot_sit_on_a_mobile_terminal(): void
    {
        $this->actingAs($this->user)
            ->post(route('device.connect'), ['virtual_device_id' => $this->mobile->id])
            ->assertSessionHasErrors('virtual_device_id');
    }

    public function test_the_owners_phone_seat_does_not_count_as_the_webs(): void
    {
        // On the phone, on Terminal Mobile 2…
        VirtualDeviceSession::create([
            'tenant_id' => $this->tenant->id, 'virtual_device_id' => $this->mobile->id, 'user_id' => $this->user->id,
            'connection_token' => (string) Str::uuid(), 'metadata' => ['install_id' => 'phone-1', 'source' => 'mobile'],
            'connected_at' => now(), 'last_seen_at' => now(),
        ]);

        // …the web still asks which web terminal to use,
        $this->actingAs($this->user)->get(route('dashboard'))->assertRedirect(route('device.select'));

        // and lets the same person take one.
        $this->actingAs($this->user)
            ->post(route('device.connect'), ['virtual_device_id' => $this->web->id])
            ->assertSessionHasNoErrors();
    }

    public function test_a_beauty_salon_gets_services_first_in_the_app(): void
    {
        $settings = $this->tenant->settings;
        $settings['company_profile']['business_mode'] = 'beauty';
        $settings['store']['business_activity'] = 'beauty';
        $this->tenant->update(['settings' => $settings]);

        $types = $this->withToken($this->user->createToken('t')->plainTextToken)
            ->getJson('/api/v1/sync/settings')->assertOk()->json('item_types');

        $this->assertSame('service', $types[0]['value']);
        $this->assertSame('Prestation', $types[0]['label']);
        $this->assertContains('supply', array_column($types, 'value'));
    }

    public function test_a_bookshop_still_opens_on_books_with_services_available(): void
    {
        $types = $this->withToken($this->user->createToken('t')->plainTextToken)
            ->getJson('/api/v1/sync/settings')->json('item_types');

        $this->assertSame('book', $types[0]['value']);
        $this->assertSame('service', end($types)['value']);
    }
}
