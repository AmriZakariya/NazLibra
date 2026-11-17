<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\VirtualDevice;
use App\Models\VirtualDeviceSession;
use App\Support\TerminalPicker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** The web terminal picker groups terminals by kind, free ones first. */
class TerminalPickerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->tenant = Tenant::firstOrFail();
        $this->user = User::where('current_tenant_id', $this->tenant->id)->firstOrFail();
        $settings = $this->tenant->settings ?? [];
        data_set($settings, 'features.virtual_devices', true);
        $this->tenant->update(['settings' => $settings]);
        VirtualDevice::where('tenant_id', $this->tenant->id)->delete();
    }

    private function terminal(string $name, ?string $type): VirtualDevice
    {
        return VirtualDevice::create([
            'tenant_id' => $this->tenant->id, 'name' => $name, 'code' => Str::slug($name).'-'.Str::random(4),
            'type' => $type, 'is_active' => true,
        ]);
    }

    private function occupy(VirtualDevice $device): void
    {
        $other = User::factory()->create(['current_tenant_id' => $this->tenant->id]);
        VirtualDeviceSession::create([
            'tenant_id' => $this->tenant->id, 'virtual_device_id' => $device->id, 'user_id' => $other->id,
            'session_id' => Str::uuid(), 'connection_token' => Str::uuid(),
            'connected_at' => now(), 'last_seen_at' => now(),
        ]);
    }

    public function test_groups_come_in_a_fixed_order_with_free_terminals_first(): void
    {
        $devices = collect([
            (object) ['id' => 1, 'name' => 'Téléphone', 'type' => 'mobile', 'is_in_use' => false],
            (object) ['id' => 2, 'name' => 'Caisse B', 'type' => 'computer', 'is_in_use' => true],
            (object) ['id' => 3, 'name' => 'Caisse A', 'type' => 'computer', 'is_in_use' => true],
            (object) ['id' => 4, 'name' => 'Caisse C', 'type' => 'computer', 'is_in_use' => false],
            (object) ['id' => 5, 'name' => 'Borne', 'type' => null, 'is_in_use' => false],
            (object) ['id' => 6, 'name' => 'Tablette', 'type' => 'tablet', 'is_in_use' => false],
        ]);

        $groups = TerminalPicker::groups($devices, currentDeviceId: 2);

        $this->assertSame(['computer', 'tablet', 'other', 'mobile'], array_column($groups, 'type'), 'an untyped terminal is "other"');
        $this->assertSame(['Caisse B', 'Caisse C', 'Caisse A'], $groups[0]['devices']->pluck('name')->all(), 'mine counts as free; free by name, then busy');
        $this->assertSame([2, 3], [$groups[0]['free'], $groups[0]['total']]);
        $this->assertFalse($groups[3]['selectable'], 'phone terminals are claimed from the app');
    }

    public function test_the_page_groups_terminals_and_folds_the_phones(): void
    {
        $this->terminal('Caisse 1', 'computer');
        $this->terminal('Tablette salle', 'tablet');
        $this->occupy($this->terminal('Caisse 2', 'computer'));
        $phone = $this->terminal('Téléphone livreur', 'mobile');

        $html = $this->actingAs($this->user)->get(route('device.select'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/data-group="computer".*Caisse 1.*Caisse 2.*data-group="tablet"/s', $html);
        $this->assertMatchesRegularExpression('/<details class="mobile-group">.*Téléphone livreur/s', $html);
        $this->assertDoesNotMatchRegularExpression('/name="virtual_device_id" value="'.$phone->id.'"/', $html, 'no radio for a phone terminal');
        $this->assertStringNotContainsString('id="device-search"', $html, 'no search box for a handful of terminals');
    }

    public function test_a_long_list_gets_a_search_box(): void
    {
        foreach (range(1, 9) as $i) {
            $this->terminal("Caisse {$i}", 'computer');
        }

        $this->actingAs($this->user)->get(route('device.select'))
            ->assertOk()
            ->assertSee('id="device-search"', false);
    }
}
