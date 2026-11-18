<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\ItemVariant;
use App\Models\Location;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * « Déclinaisons » edited from the till: PUT /api/v1/items/{item}/variants.
 */
class ApiItemVariantsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Location $location;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->tenant = Tenant::firstOrFail();
        $this->location = Location::where('tenant_id', $this->tenant->id)->firstOrFail();
        $this->token = $this->loginAs('amina@librairie-atlas.ma');
    }

    private function loginAs(string $email): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $email, 'password' => 'password', 'device_name' => 'variants-'.$email,
        ])->assertOk()->json('token');
    }

    private function putVariants(Item $item, array $payload, ?string $token = null)
    {
        // The guard keeps the first request's user for the whole test.
        $this->app['auth']->forgetGuards();

        return $this->withToken($token ?? $this->token)
            ->withHeader('X-Tenant-Slug', $this->tenant->slug)
            ->withHeader('X-Location-Id', (string) $this->location->id)
            ->putJson('/api/v1/items/'.$item->id.'/variants', $payload);
    }

    private function item(string $type, string $title = 'Massage'): Item
    {
        return Item::create([
            'tenant_id' => $this->tenant->id, 'title' => $title, 'type' => $type, 'status' => 'active',
            'sale_price' => 100, 'purchase_price' => 0, 'stock_quantity' => 0,
        ]);
    }

    private function variant(Item $item, string $name, float $price, ?int $minutes = null): ItemVariant
    {
        return $item->variants()->create([
            'tenant_id' => $this->tenant->id, 'name' => $name, 'attributes' => [],
            'sale_price' => $price, 'sale_price_override' => $price, 'duration_minutes' => $minutes,
            'is_active' => true, 'sort_order' => $item->variants()->count(),
        ]);
    }

    private function userWithPermissions(array $permissions): string
    {
        $role = Role::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Limité', 'key' => 'limited', 'permissions' => $permissions,
        ]);
        $user = User::create(['name' => 'Limité', 'email' => 'limited@example.test', 'password' => Hash::make('password')]);
        $this->tenant->users()->attach($user->id, ['role' => $role->key]);

        return $this->loginAs('limited@example.test');
    }

    public function test_it_adds_edits_and_orders_a_services_variants_with_their_durations(): void
    {
        $item = $this->item('service');
        $short = $this->variant($item, '30 min', 100, 30);

        $response = $this->putVariants($item, ['variants' => [
            ['id' => $short->id, 'name' => '30 min', 'sale_price' => 120, 'duration_minutes' => 30],
            ['local_id' => 'abc', 'name' => '1 h', 'sale_price' => 200, 'duration_minutes' => 60],
        ]])->assertOk();

        $this->assertSame(['30 min', '1 h'], collect($response->json('variants'))->pluck('name')->all());
        $this->assertEqualsWithDelta(120, $response->json('variants.0.sale_price'), 0.001);
        $new = ItemVariant::where('name', '1 h')->firstOrFail();
        $this->assertSame($new->id, $response->json('ids.abc'));
        // The typed price is the variant's own, not the article's.
        $this->assertEqualsWithDelta(200, $new->price(), 0.001);
        $this->assertSame(60, $new->duration_minutes);
        $this->assertSame(2, $item->fresh()->variant_count);
    }

    public function test_a_product_variant_takes_barcode_and_sku_but_no_duration(): void
    {
        $item = $this->item('supply', 'Shampoing');

        $this->putVariants($item, ['variants' => [
            ['name' => '250 ml', 'sale_price' => 30, 'barcode' => '611001', 'sku' => 'SH-250', 'duration_minutes' => 45],
        ]])->assertOk();

        $variant = $item->variants()->firstOrFail();
        $this->assertSame('611001', $variant->barcode);
        $this->assertSame('SH-250', $variant->sku);
        $this->assertNull($variant->duration_minutes);
    }

    public function test_a_variant_used_on_a_sale_is_archived_never_deleted(): void
    {
        $item = $this->item('service');
        $sold = $this->variant($item, '1 h', 200, 60);
        $unsold = $this->variant($item, '2 h', 350, 120);
        $sale = Sale::create([
            'tenant_id' => $this->tenant->id, 'number' => 'BL90001', 'status' => 'paid', 'payment_method' => 'cash',
            'subtotal_amount' => 200, 'total_amount' => 200, 'sold_at' => now(),
        ]);
        $sale->items()->create([
            'item_id' => $item->id, 'variant_id' => $sold->id, 'name' => 'Massage — 1 h',
            'quantity' => 1, 'unit_price' => 200, 'total_price' => 200,
        ]);

        $response = $this->putVariants($item, ['remove' => [$sold->id, $unsold->id]])->assertOk();

        $this->assertEqualsCanonicalizing([$sold->id, $unsold->id], $response->json('removed'));
        $this->assertSame([], $response->json('variants'));
        $this->assertFalse($sold->fresh()->is_active);
        $this->assertFalse($unsold->fresh()->is_active);
        $this->assertDatabaseHas('sale_items', ['variant_id' => $sold->id]);
        $this->assertSame(0, $item->fresh()->variant_count);
    }

    /** A till offline while the variant was removed sold it in good faith: accepted, marked, journaled — never stuck in its outbox. */
    public function test_an_offline_sale_of_a_since_archived_variant_is_accepted_and_journaled(): void
    {
        $item = $this->item('service');
        $this->variant($item, '1 h', 200, 60);
        $gone = $this->variant($item, '2 h', 350, 120);
        $this->putVariants($item, ['remove' => [$gone->id]])->assertOk();
        $this->assertFalse($gone->fresh()->is_active);

        $this->app['auth']->forgetGuards();
        $saleId = $this->withToken($this->token)
            ->withHeader('X-Tenant-Slug', $this->tenant->slug)
            ->withHeader('X-Location-Id', (string) $this->location->id)
            ->postJson('/api/v1/pos/sales', [
                'idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
                'location_id' => $this->location->id,
                'items' => [['item_id' => $item->id, 'variant_id' => $gone->id, 'quantity' => 1]],
                'payments' => ['cash' => 350, 'card' => 0, 'transfer' => 0, 'advance' => 0],
            ])->assertCreated()->json('sale.id');

        $sale = Sale::findOrFail($saleId);
        $this->assertSame($gone->id, (int) $sale->items()->value('variant_id'));
        $this->assertSame($gone->id, data_get($sale->metadata, 'archived_variants.0.variant_id'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'api.archived_variant_sold', 'subject_id' => $sale->id]);
    }

    public function test_names_must_be_unique_per_item_and_a_name_can_be_reused_after_removal(): void
    {
        $item = $this->item('service');
        $one = $this->variant($item, '1 h', 200, 60);

        $this->putVariants($item, ['variants' => [['name' => '1 H', 'sale_price' => 10]]])
            ->assertStatus(200); // same name: it is the existing variant, updated, not a twin
        $this->assertSame(1, $item->variants()->count());

        $this->putVariants($item, ['variants' => [
            ['id' => $one->id, 'name' => 'Duo', 'sale_price' => 10],
            ['name' => 'duo', 'sale_price' => 20],
        ]])->assertUnprocessable()->assertJsonValidationErrors('variants.1.name');

        $other = $this->variant($item, '2 h', 300, 120);
        $this->putVariants($item, ['variants' => [['id' => $other->id, 'name' => '1 H', 'sale_price' => 300]]])
            ->assertUnprocessable()->assertJsonValidationErrors('variants.0.name');

        // Removed, the name is free again.
        $this->putVariants($item, ['remove' => [$one->id], 'variants' => [['id' => $other->id, 'name' => '1 H', 'sale_price' => 300]]])
            ->assertOk();
    }

    public function test_a_retry_after_a_lost_response_does_not_duplicate(): void
    {
        $item = $this->item('service');
        $payload = ['variants' => [['local_id' => 'x', 'name' => '45 min', 'sale_price' => 150, 'duration_minutes' => 45]]];

        $first = $this->putVariants($item, $payload)->assertOk()->json('ids.x');
        $second = $this->putVariants($item, $payload)->assertOk()->json('ids.x');

        $this->assertSame($first, $second);
        $this->assertSame(1, $item->variants()->count());
    }

    public function test_validation_rejects_a_blank_name_a_negative_price_and_a_bad_duration(): void
    {
        $item = $this->item('service');

        $this->putVariants($item, ['variants' => [['name' => '', 'sale_price' => 10]]])
            ->assertJsonValidationErrors('variants.0.name');
        $this->putVariants($item, ['variants' => [['name' => 'A', 'sale_price' => -1]]])
            ->assertJsonValidationErrors('variants.0.sale_price');
        $this->putVariants($item, ['variants' => [['name' => 'A', 'sale_price' => 1, 'duration_minutes' => 4]]])
            ->assertJsonValidationErrors('variants.0.duration_minutes');
        $this->putVariants($item, ['variants' => [['name' => 'A', 'sale_price' => 1, 'duration_minutes' => 601]]])
            ->assertJsonValidationErrors('variants.0.duration_minutes');
        $this->assertSame(0, $item->variants()->count());
    }

    public function test_editing_needs_items_edit_and_removing_needs_items_delete(): void
    {
        $item = $this->item('service');
        $variant = $this->variant($item, '1 h', 200, 60);

        $viewer = $this->userWithPermissions(['items.view']);
        $this->putVariants($item, ['variants' => [['id' => $variant->id, 'name' => 'x', 'sale_price' => 1]]], $viewer)
            ->assertForbidden();
        $this->assertSame('1 h', $variant->fresh()->name);
    }

    public function test_an_editor_without_the_delete_right_cannot_remove(): void
    {
        $item = $this->item('service');
        $variant = $this->variant($item, '1 h', 200, 60);
        $editor = $this->userWithPermissions(['items.view', 'items.edit']);

        $this->putVariants($item, ['variants' => [['id' => $variant->id, 'name' => '1 h', 'sale_price' => 250]]], $editor)
            ->assertOk();
        $this->putVariants($item, ['remove' => [$variant->id]], $editor)->assertForbidden();
        $this->assertTrue($variant->fresh()->is_active);
    }

    public function test_another_shops_variant_is_untouchable(): void
    {
        $item = $this->item('service');
        $foreign = Tenant::create(['name' => 'Autre', 'slug' => 'autre', 'status' => 'active']);
        $theirs = Item::create([
            'tenant_id' => $foreign->id, 'title' => 'X', 'type' => 'service', 'status' => 'active',
            'sale_price' => 1, 'purchase_price' => 0, 'stock_quantity' => 0,
        ]);
        $theirVariant = ItemVariant::create([
            'tenant_id' => $foreign->id, 'item_id' => $theirs->id, 'name' => 'V', 'attributes' => [], 'sale_price' => 1, 'is_active' => true,
        ]);

        $this->putVariants($item, ['remove' => [$theirVariant->id]])->assertOk();
        $this->assertTrue($theirVariant->fresh()->is_active);
        $this->putVariants($theirs, ['remove' => [$theirVariant->id]])->assertNotFound();
        $this->putVariants($item, ['variants' => [['id' => $theirVariant->id, 'name' => 'hack', 'sale_price' => 1]]])
            ->assertUnprocessable();
    }
}
