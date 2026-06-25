<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\VirtualDevice;
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
