<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Tax;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A service has no purchase cost but a duration: the web form asks for the
 * one and not the other, and the duration reaches the till.
 */
class ServiceDurationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->tenant = Tenant::first();
        $this->owner = User::first();
    }

    public function test_the_web_service_form_asks_for_a_duration_not_a_cost(): void
    {
        $this->actingAs($this->owner)
            ->get(route('catalog', ['panel' => 'ajouter-service']))
            ->assertOk()
            ->assertSee('name="duration_minutes"', false)
            ->assertDontSee('Coût interne')
            ->assertSee('Calculé depuis le prix de vente');
    }

    public function test_a_service_saved_on_the_web_keeps_its_duration_and_a_product_none(): void
    {
        $unit = Unit::where('tenant_id', $this->tenant->id)->first();
        $tax = Tax::where('tenant_id', $this->tenant->id)->first();
        $base = [
            'unit_id' => $unit->id, 'tax_id' => $tax->id, 'purchase_price' => 0, 'sale_price' => 80,
            'stock_quantity' => 0, 'min_stock_threshold' => 0, 'duration_minutes' => 45,
        ];

        $this->actingAs($this->owner)->post(route('catalog.items.store'), $base + ['type' => 'service', 'title' => 'Coloration'])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post(route('catalog.items.store'), $base + ['type' => 'supply', 'title' => 'Shampooing'])
            ->assertSessionHasNoErrors();

        $this->assertSame(45, Item::where('title', 'Coloration')->value('duration_minutes'));
        $this->assertNull(Item::where('title', 'Shampooing')->value('duration_minutes'));
    }

    public function test_the_till_receives_the_duration_and_can_set_it(): void
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $this->owner->email, 'password' => 'password', 'device_name' => 'till-'.Str::uuid(),
        ])->json('token');
        $unit = Unit::where('tenant_id', $this->tenant->id)->first();
        $tax = Tax::where('tenant_id', $this->tenant->id)->first();

        $created = $this->withToken($token)->postJson('/api/v1/items', [
            'local_id' => (string) Str::uuid(), 'title' => 'Barbe', 'type' => 'service',
            'sale_price' => 30, 'unit_id' => $unit->id, 'tax_id' => $tax->id, 'duration_minutes' => 20,
        ])->assertSuccessful()->json('item');
        $this->assertSame(20, $created['duration_minutes']);

        // The catalogue pull carries it, so the till books a service for as
        // long as it takes. (A pull leaves out rows written in its own second.)
        $this->travel(5)->seconds();
        $items = $this->withToken($token)->getJson('/api/v1/sync/items')->assertOk()->json('items');
        $this->assertArrayHasKey('duration_minutes', $items[0]);
    }
}
