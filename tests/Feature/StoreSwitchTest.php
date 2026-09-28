<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VirtualDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreSwitchTest extends TestCase
{
    use RefreshDatabase;

    public function test_header_posts_real_location_ids_instead_of_legacy_store_slugs(): void
    {
        $this->seed();
        $tenant = Tenant::firstOrFail();
        $a = Location::where('tenant_id', $tenant->id)->firstOrFail();
        $b = Location::create(['tenant_id' => $tenant->id, 'name' => 'Nouvelle boutique', 'type' => 'store', 'is_active' => true]);
        $settings = $tenant->settings;
        $settings['stores'] = [['key' => 'legacy-slug', 'name' => 'Ancien magasin']];
        $settings['current_store'] = (string) $a->id;
        $tenant->update(['settings' => $settings]);
        $response = $this->get('/modules/settings?section=warehouses')->assertOk();
        preg_match_all('/<select name="current_store"[^>]*>(.*?)<\/select>/s', $response->getContent(), $selects);
        $this->assertNotEmpty($selects[1]);
        foreach ($selects[1] as $options) {
            $this->assertStringContainsString('value="'.$b->id.'"', $options);
            $this->assertStringNotContainsString('legacy-slug', $options);
        }
        $this->post('/parametres/magasin-courant', ['current_store' => (string) $b->id])->assertSessionHasNoErrors();
        $this->assertSame((string) $b->id, $tenant->fresh()->settings['current_store']);
        $this->assertTrue($b->fresh()->is_default);
    }

    public function test_location_list_and_requests_respect_store_access_and_active_state(): void
    {
        $this->seed();
        $tenant = Tenant::firstOrFail();
        $a = Location::where('tenant_id', $tenant->id)->firstOrFail();
        $b = Location::create(['tenant_id' => $tenant->id, 'name' => 'Restricted shop', 'type' => 'store', 'is_active' => true]);
        $disabled = Location::create(['tenant_id' => $tenant->id, 'name' => 'Closed shop', 'type' => 'store', 'is_active' => false]);
        $user = User::factory()->create(['current_tenant_id' => $tenant->id]);
        $tenant->users()->attach($user->id, ['role' => 'custom-cashier', 'store_access' => json_encode([$a->name])]);
        $this->actingAs($user);
        $this->withHeader('X-Location-Id', 'obsolete')->getJson('/api/v1/locations')->assertOk()
            ->assertJsonCount(1, 'locations')->assertJsonPath('locations.0.id', $a->id);
        $this->withHeader('X-Location-Id', (string) $b->id)->getJson('/api/v1/auth/me')->assertStatus(422);
        $this->withHeader('X-Location-Id', (string) $disabled->id)->getJson('/api/v1/auth/me')->assertStatus(422);
        $this->withHeader('X-Location-Id', (string) $a->id)->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('location.id', $a->id);
    }

    public function test_terminal_claim_cannot_change_store_implicitly(): void
    {
        $this->seed();
        $tenant = Tenant::firstOrFail();
        $current = Location::where('tenant_id', $tenant->id)->firstOrFail();
        $other = Location::create(['tenant_id' => $tenant->id, 'name' => 'Other shop', 'type' => 'store', 'is_active' => true]);
        $device = VirtualDevice::create(['tenant_id' => $tenant->id, 'location_id' => $other->id,
            'name' => 'Other terminal', 'code' => 'OTHER', 'type' => 'mobile', 'is_active' => true]);
        $this->withHeader('X-Location-Id', (string) $current->id)
            ->postJson('/api/v1/virtual-devices/connect', ['virtual_device_id' => $device->id, 'install_id' => 'tablet'])
            ->assertStatus(422)->assertJsonPath('error', 'invalid_virtual_device');
    }
}
