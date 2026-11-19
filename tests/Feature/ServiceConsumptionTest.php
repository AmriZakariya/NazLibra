<?php

namespace Tests\Feature;

use App\Models\InventoryMovement;
use App\Models\Item;
use App\Models\ItemComponent;
use App\Models\ItemLocationStock;
use App\Models\Location;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleItemConsumption;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Catalogue\ServiceConsumption;
use App\Services\Inventory\InventoryLedgerService;
use App\Services\Inventory\InventoryMovementType;
use App\Support\ConsumptionReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * « Produits utilisés »: a service consumes products in fractions of the unit
 * they are stocked in, and the cost lands in the margin.
 */
class ServiceConsumptionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Location $location;

    private User $owner;

    private Item $gel;      // 200 ml bottle, bought at 40

    private Item $hydratant; // 250 g pot, bought at 100

    private Item $purifiant; // 250 g pot, bought at 80

    private Item $gloves;   // no capacity: stocked in pieces

    private Item $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->tenant = Tenant::firstOrFail();
        // The web till sells from the default location: stock lives there.
        $this->location = Location::where('tenant_id', $this->tenant->id)->where('is_default', true)->first()
            ?? Location::where('tenant_id', $this->tenant->id)->firstOrFail();
        $this->owner = User::where('email', 'amina@librairie-atlas.ma')->firstOrFail();

        $this->gel = $this->product('Gel nettoyant', 200, 'ml', 40, 10);
        $this->hydratant = $this->product('Masque hydratant', 250, 'g', 100, 4);
        $this->purifiant = $this->product('Masque purifiant', 250, 'g', 80, 4);
        $this->gloves = $this->product('Gants jetables', null, null, 0.5, 100);

        $this->service = Item::create([
            'tenant_id' => $this->tenant->id, 'title' => 'Soin visage', 'type' => 'service', 'status' => 'active',
            'sale_price' => 200, 'purchase_price' => 0, 'stock_quantity' => 0,
        ]);
        $this->recipe([
            ['component_item_id' => $this->gel->id, 'quantity' => 10],
            ['component_item_id' => $this->hydratant->id, 'quantity' => 10, 'choice_group' => 'Masque', 'is_default' => true],
            ['component_item_id' => $this->purifiant->id, 'quantity' => 10, 'choice_group' => 'Masque'],
            ['component_item_id' => $this->gloves->id, 'quantity' => 2],
        ]);
    }

    private function product(string $title, ?float $content, ?string $unit, float $cost, int $stock): Item
    {
        $item = Item::create([
            'tenant_id' => $this->tenant->id, 'title' => $title, 'type' => 'supply', 'status' => 'active',
            'sale_price' => 0, 'purchase_price' => $cost, 'stock_quantity' => 0,
            'content_quantity' => $content, 'content_unit' => $unit,
        ]);
        if ($stock > 0) {
            app(InventoryLedgerService::class)->createIncomingMovement([
                'tenantId' => $this->tenant->id, 'itemId' => $item->id, 'variantId' => null,
                'locationId' => $this->location->id, 'type' => InventoryMovementType::INITIAL_STOCK,
                'quantity' => $stock, 'unitCost' => $cost, 'occurredAt' => now()->subMinutes(5),
                'syncedAt' => null, 'userId' => null, 'idempotencyKey' => 'setup-'.$item->id,
                'referenceType' => null, 'referenceId' => null, 'referenceNumber' => null, 'reason' => null,
                'note' => null, 'virtualDeviceId' => null, 'actorNameSnapshot' => null, 'terminalNameSnapshot' => null,
            ]);
        }

        return $item->refresh();
    }

    private function recipe(array $rows, ?Item $service = null): void
    {
        foreach ($rows as $i => $row) {
            ItemComponent::create($row + [
                'tenant_id' => $this->tenant->id, 'item_id' => ($service ?? $this->service)->id,
                'sort_order' => $i, 'variant_id' => null, 'choice_group' => null, 'is_default' => false,
            ]);
        }
    }

    private function onHand(Item $item): float
    {
        return (float) ItemLocationStock::where('tenant_id', $this->tenant->id)
            ->where('item_id', $item->id)->where('location_id', $this->location->id)->value('quantity');
    }

    private function token(): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $this->owner->email, 'password' => 'password', 'device_name' => 'till-'.Str::uuid(),
        ])->json('token');
    }

    /** @param array<string, mixed> $line */
    private function sell(array $line = [], ?string $key = null): \Illuminate\Testing\TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->token())->postJson('/api/v1/pos/sales', [
            'idempotency_key' => $key ?? Str::uuid()->toString(),
            'location_id' => $this->location->id,
            'items' => [array_merge(['item_id' => $this->service->id, 'quantity' => 1], $line)],
            'payments' => ['cash' => 1000, 'card' => 0, 'transfer' => 0, 'advance' => 0],
        ]);
    }

    public function test_a_capacity_converts_the_content_unit_into_stocked_units(): void
    {
        $this->assertSame(0.05, ServiceConsumption::toStocked($this->gel, 10.0));
        $this->assertSame(0.1, ServiceConsumption::toStocked($this->gel, 20.0));
        // No capacity: the recipe is already in stocked units.
        $this->assertSame(2.0, ServiceConsumption::toStocked($this->gloves, 2.0));
        $this->assertTrue($this->gel->hasCapacity());
        $this->assertFalse($this->gloves->hasCapacity());
        app()->setLocale('fr');
        $this->assertSame('3,45 unités (690 ml)', \App\Support\ContentUnits::describeStock($this->gel, 3.45));
    }

    public function test_the_effective_recipe_applies_a_variants_override_removal_and_addition(): void
    {
        $short = $this->service->variants()->create([
            'tenant_id' => $this->tenant->id, 'name' => 'Express', 'attributes' => [], 'sale_price' => 120,
            'sale_price_override' => 120, 'is_active' => true, 'sort_order' => 0,
        ]);
        $this->recipe([
            ['component_item_id' => $this->gel->id, 'quantity' => 4, 'variant_id' => $short->id],        // overrides 10 -> 4
            ['component_item_id' => $this->gloves->id, 'quantity' => 0, 'variant_id' => $short->id],     // removed
            ['component_item_id' => $this->hydratant->id, 'quantity' => 0, 'variant_id' => $short->id, 'choice_group' => 'Masque'], // member removed
        ]);
        $consumption = app(ServiceConsumption::class);

        $base = $consumption->recipe($this->tenant->id, $this->service->id)->keyBy('component_item_id');
        $this->assertEquals(10, $base[$this->gel->id]['quantity']);
        $this->assertTrue($base->has($this->gloves->id));

        $express = $consumption->recipe($this->tenant->id, $this->service->id, $short->id)->keyBy('component_item_id');
        $this->assertEquals(4, $express[$this->gel->id]['quantity']);
        $this->assertFalse($express->has($this->gloves->id), 'quantity 0 on a variant removes the base component');
        $this->assertFalse($express->has($this->hydratant->id));
        $this->assertTrue($express->has($this->purifiant->id));

        // The variant's own plan: 4 ml of gel only, and the only remaining mask is the used one.
        $plan = collect($consumption->plan($this->service, $short, 2));
        $this->assertEqualsWithDelta(8.0, $plan->firstWhere('component_item_id', $this->gel->id)['quantity_content'], 0.0001);
        $this->assertEqualsWithDelta(0.04, $plan->firstWhere('component_item_id', $this->gel->id)['quantity_stock'], 0.0001);
        $this->assertNotNull($plan->firstWhere('component_item_id', $this->purifiant->id));
    }

    public function test_a_choice_group_uses_the_default_or_the_explicit_choice_and_ignores_strangers(): void
    {
        $consumption = app(ServiceConsumption::class);

        $default = collect($consumption->plan($this->service, null, 1))->pluck('component_item_id');
        $this->assertTrue($default->contains($this->hydratant->id));
        $this->assertFalse($default->contains($this->purifiant->id), 'exactly one alternative is used');

        $chosen = collect($consumption->plan($this->service, null, 1, [['group' => 'Masque', 'component_item_id' => $this->purifiant->id]]))->pluck('component_item_id');
        $this->assertTrue($chosen->contains($this->purifiant->id));
        $this->assertFalse($chosen->contains($this->hydratant->id));

        // The map form is accepted too.
        $map = collect($consumption->plan($this->service, null, 1, ['Masque' => $this->purifiant->id]))->pluck('component_item_id');
        $this->assertTrue($map->contains($this->purifiant->id));

        // A product outside the group is ignored (the default applies) and reported.
        $ignored = [];
        $stale = collect($consumption->plan($this->service, null, 1, [['group' => 'Masque', 'component_item_id' => $this->gel->id]], [], $ignored))->pluck('component_item_id');
        $this->assertTrue($stale->contains($this->hydratant->id));
        $this->assertSame('choice', $ignored[0]['kind']);
    }

    public function test_an_api_sale_deducts_fractions_records_movements_and_snapshots_the_cost(): void
    {
        $response = $this->sell(['quantity' => 2])->assertCreated();

        // 2 services x 10 ml of a 200 ml bottle = 0.1 bottle.
        $this->assertEqualsWithDelta(9.9, $this->onHand($this->gel), 0.0001);
        $this->assertEqualsWithDelta(3.92, $this->onHand($this->hydratant), 0.0001);   // 20 g of 250 g
        $this->assertEqualsWithDelta(96.0, $this->onHand($this->gloves), 0.0001);       // 4 pieces
        $this->assertEqualsWithDelta(4.0, $this->onHand($this->purifiant), 0.0001);     // not chosen

        $movement = InventoryMovement::where('item_id', $this->gel->id)->where('type', InventoryMovementType::SERVICE_CONSUMPTION)->sole();
        $this->assertEqualsWithDelta(-0.1, (float) $movement->quantity_delta, 0.00001);
        $this->assertSame(SaleItem::class, $movement->reference_type);
        $this->assertSame('Consommation interne', $movement->reason);

        // Cost: 0.1 x 40 + 0.08 x 100 + 4 x 0.5 = 4 + 8 + 2 = 14.
        $line = SaleItem::where('item_id', $this->service->id)->sole();
        $this->assertEqualsWithDelta(14.0, (float) $line->total_cost, 0.0001);
        $this->assertEqualsWithDelta(7.0, (float) $line->unit_cost, 0.0001);
        $sale = Sale::findOrFail($response->json('sale.id'));
        $this->assertEqualsWithDelta(14.0, (float) $sale->cogs, 0.0001);

        $snapshot = SaleItemConsumption::where('sale_item_id', $line->id)->where('component_item_id', $this->gel->id)->sole();
        $this->assertEqualsWithDelta(20.0, (float) $snapshot->quantity_content, 0.0001);
        $this->assertEqualsWithDelta(0.1, (float) $snapshot->quantity_stock, 0.0001);
        $this->assertSame('ml', $snapshot->content_unit);
        $this->assertEqualsWithDelta(40.0, (float) $snapshot->unit_cost, 0.0001);

        $this->assertCount(3, $response->json('consumption'));
        $this->assertEqualsWithDelta(9.9, (float) $response->json('stock_after.'.$this->gel->id), 0.0001);
    }

    public function test_a_replayed_sale_does_not_deduct_twice(): void
    {
        $key = Str::uuid()->toString();
        $this->sell([], $key)->assertCreated();
        $this->sell([], $key)->assertOk();

        $this->assertEqualsWithDelta(9.95, $this->onHand($this->gel), 0.0001);
        $this->assertSame(1, InventoryMovement::where('item_id', $this->gel->id)->where('type', InventoryMovementType::SERVICE_CONSUMPTION)->count());
        $this->assertSame(3, SaleItemConsumption::count());
    }

    public function test_the_till_chooses_an_alternative_and_adjusts_a_quantity(): void
    {
        $this->sell([
            'component_choices' => [['group' => 'Masque', 'component_item_id' => $this->purifiant->id]],
            'component_overrides' => [['component_item_id' => $this->gel->id, 'quantity' => 20]],
        ])->assertCreated();

        $this->assertEqualsWithDelta(9.9, $this->onHand($this->gel), 0.0001);         // 20 ml, not 10
        $this->assertEqualsWithDelta(3.96, $this->onHand($this->purifiant), 0.0001);   // the chosen mask
        $this->assertEqualsWithDelta(4.0, $this->onHand($this->hydratant), 0.0001);    // the default was not used
    }

    public function test_a_stale_choice_or_adjustment_never_blocks_the_sale_and_is_journaled(): void
    {
        $stranger = $this->product('Autre produit', 100, 'ml', 10, 5);

        $response = $this->sell([
            'component_overrides' => [['component_item_id' => $stranger->id, 'quantity' => 5]],
            'component_choices' => [['group' => 'Masque', 'component_item_id' => $stranger->id], ['group' => 'Fantôme', 'component_item_id' => $stranger->id]],
        ])->assertCreated();

        // The default recipe applied; the stranger was not touched.
        $this->assertEqualsWithDelta(9.95, $this->onHand($this->gel), 0.0001);
        $this->assertEqualsWithDelta(3.96, $this->onHand($this->hydratant), 0.0001);
        $this->assertEqualsWithDelta(5.0, $this->onHand($stranger), 0.0001);
        $this->assertSame(3, SaleItemConsumption::count());

        $sale = Sale::where('tenant_id', $this->tenant->id)->latest('id')->first();
        $this->assertCount(1, $sale->metadata['stale_recipe_choices']);
        $this->assertCount(3, $sale->metadata['stale_recipe_choices'][0]['ignored']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'api.stale_recipe_choice', 'subject_id' => $sale->id]);
    }

    public function test_a_malformed_component_payload_is_still_refused(): void
    {
        $this->sell(['component_overrides' => [['component_item_id' => $this->gel->id, 'quantity' => -3]]])->assertStatus(422);
        $this->sell(['component_overrides' => [['component_item_id' => 'abc', 'quantity' => 3]]])->assertStatus(422);
        $this->assertSame(0, SaleItemConsumption::count());
    }

    public function test_a_sale_without_choices_still_works_for_an_older_app(): void
    {
        $this->sell()->assertCreated();

        // The default mask, the fixed products: a line with no new field at all.
        $this->assertEqualsWithDelta(3.96, $this->onHand($this->hydratant), 0.0001);
        $this->assertEqualsWithDelta(9.95, $this->onHand($this->gel), 0.0001);
    }

    public function test_a_low_bottle_never_blocks_the_sale_and_its_shortfall_is_costed(): void
    {
        // Almost nothing left on the shelf: 0.01 bottle, with oversell NOT allowed.
        $rare = $this->product('Sérum rare', 30, 'ml', 150, 0);
        $this->recipe([['component_item_id' => $rare->id, 'quantity' => 3]]);

        $this->sell()->assertCreated();

        $snapshot = SaleItemConsumption::where('component_item_id', $rare->id)->sole();
        $this->assertEqualsWithDelta(0.1, (float) $snapshot->quantity_stock, 0.0001);
        $this->assertEqualsWithDelta(0.1, (float) $snapshot->shortfall_stock, 0.0001, 'the part the shelf lacked is flagged');
        // Priced at the purchase price: 0.1 x 150.
        $this->assertEqualsWithDelta(15.0, (float) $snapshot->total_cost, 0.0001);
        $this->assertSame('out_of_stock', $rare->fresh()->status, 'the usual low-stock status shows it');
    }

    public function test_the_web_till_deducts_the_same_way(): void
    {
        $this->actingAs($this->owner);
        $this->post(route('pos.store'), [
            'cart' => json_encode([[
                'id' => $this->service->id, 'quantity' => 2,
                'component_choices' => [['group' => 'Masque', 'component_item_id' => $this->purifiant->id]],
            ]]),
            'cash_amount' => 1000,
        ])->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(9.9, $this->onHand($this->gel), 0.0001);
        $this->assertEqualsWithDelta(3.92, $this->onHand($this->purifiant), 0.0001);
        $line = SaleItem::where('item_id', $this->service->id)->sole();
        // 4 + 0.08 x 80 + 4 x 0.5 = 12.4
        $this->assertEqualsWithDelta(12.4, (float) $line->total_cost, 0.0001);
        $this->assertEqualsWithDelta(12.4, (float) Sale::latest('id')->firstOrFail()->cogs, 0.0001);
    }

    public function test_an_api_refund_gives_the_products_back_proportionally(): void
    {
        $saleId = $this->sell(['quantity' => 2])->assertCreated()->json('sale.id');
        $line = SaleItem::where('sale_id', $saleId)->sole();

        // One of the two services refunded, then the other.
        $this->app['auth']->forgetGuards();
        $this->withToken($this->token())->postJson("/api/v1/pos/sales/{$saleId}/returns", [
            'idempotency_key' => Str::uuid()->toString(), 'refund_method' => 'cash',
            'return_lines' => [['sale_item_id' => $line->id, 'quantity' => 1, 'stock_action' => 'restock']],
        ])->assertCreated();

        $this->assertEqualsWithDelta(9.95, $this->onHand($this->gel), 0.0001);
        $this->assertEqualsWithDelta(98.0, $this->onHand($this->gloves), 0.0001);
        $this->assertSame(
            InventoryMovementType::SERVICE_CONSUMPTION_RETURN,
            InventoryMovement::where('item_id', $this->gel->id)->orderByDesc('id')->value('type'),
        );

        $this->app['auth']->forgetGuards();
        $this->withToken($this->token())->postJson("/api/v1/pos/sales/{$saleId}/returns", [
            'idempotency_key' => Str::uuid()->toString(), 'refund_method' => 'cash',
            'return_lines' => [['sale_item_id' => $line->id, 'quantity' => 1, 'stock_action' => 'restock']],
        ])->assertCreated();

        $this->assertEqualsWithDelta(10.0, $this->onHand($this->gel), 0.0001);
        $this->assertEqualsWithDelta(100.0, $this->onHand($this->gloves), 0.0001);
        $this->assertEqualsWithDelta(4.0, $this->onHand($this->hydratant), 0.0001);
        $this->assertEqualsWithDelta(0.1, (float) SaleItemConsumption::where('component_item_id', $this->gel->id)->value('returned_stock'), 0.0001);
    }

    public function test_a_refund_without_restock_gives_nothing_back(): void
    {
        $saleId = $this->sell()->assertCreated()->json('sale.id');
        $line = SaleItem::where('sale_id', $saleId)->sole();

        $this->app['auth']->forgetGuards();
        $this->withToken($this->token())->postJson("/api/v1/pos/sales/{$saleId}/returns", [
            'idempotency_key' => Str::uuid()->toString(), 'refund_method' => 'cash',
            'return_lines' => [['sale_item_id' => $line->id, 'quantity' => 1, 'stock_action' => 'no_restock']],
        ])->assertCreated();

        $this->assertEqualsWithDelta(9.95, $this->onHand($this->gel), 0.0001);
    }

    public function test_a_web_refund_gives_the_products_back(): void
    {
        $this->actingAs($this->owner);
        $this->post(route('pos.store'), [
            'cart' => json_encode([['id' => $this->service->id, 'quantity' => 1]]),
            'cash_amount' => 1000,
        ])->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(9.95, $this->onHand($this->gel), 0.0001);

        $this->post(route('sales.refund', Sale::latest('id')->firstOrFail()), ['refund_method' => 'cash', 'refund_reason' => 'erreur'])
            ->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(10.0, $this->onHand($this->gel), 0.0001);
        $this->assertEqualsWithDelta(100.0, $this->onHand($this->gloves), 0.0001);
    }

    public function test_the_consumption_report_totals_by_product_and_by_service_net_of_refunds(): void
    {
        $this->sell(['quantity' => 2])->assertCreated();
        $saleId = $this->sell(['quantity' => 2])->assertCreated()->json('sale.id');
        $line = SaleItem::where('sale_id', $saleId)->sole();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->token())->postJson("/api/v1/pos/sales/{$saleId}/returns", [
            'idempotency_key' => Str::uuid()->toString(), 'refund_method' => 'cash',
            'return_lines' => [['sale_item_id' => $line->id, 'quantity' => 2, 'stock_action' => 'restock']],
        ])->assertCreated();

        $report = ConsumptionReport::compute($this->tenant->id, now()->subDay()->toDateTimeString(), now()->addDay()->toDateTimeString());
        $gel = collect($report['by_product'])->firstWhere('item_id', $this->gel->id);
        // Only the first sale remains consumed: 2 x 10 ml of gel = 0.1 bottle, 4 MAD.
        $this->assertEqualsWithDelta(20.0, $gel['quantity_content'], 0.001);
        $this->assertEqualsWithDelta(0.1, $gel['quantity_stock'], 0.0001);
        $this->assertEqualsWithDelta(4.0, $gel['cost'], 0.001);
        $this->assertSame('ml', $gel['content_unit']);
        $this->assertEqualsWithDelta(14.0, $report['totals']['cost'], 0.001);
        $service = collect($report['by_service'])->firstWhere('item_id', $this->service->id);
        $this->assertEqualsWithDelta(14.0, $service['cost'], 0.001);

        // The same through the API and the back office.
        $this->gel->update(['content_container' => 'flacon']);
        $this->app['auth']->forgetGuards();
        $api = $this->withToken($this->token())
            ->withHeader('X-Tenant-Slug', $this->tenant->slug)->withHeader('X-Location-Id', (string) $this->location->id)
            ->getJson('/api/v1/statistics/consumption?from='.now()->subDay()->toDateString().'&to='.now()->addDay()->toDateString())
            ->assertOk();
        $this->assertEqualsWithDelta(14.0, $api->json('totals.cost'), 0.001);
        // What one stocked unit is called, or null: « ≈ 0,1 flacon » instead of « unités ».
        $rows = collect($api->json('by_product'));
        $this->assertSame('flacon', $rows->firstWhere('item_id', $this->gel->id)['content_container']);
        $this->assertTrue($rows->every(fn ($r) => array_key_exists('content_container', $r)));
        $this->assertNull($rows->firstWhere('item_id', $this->gloves->id)['content_container']);

        $this->actingAs($this->owner)
            ->get(route('module', ['module' => 'reports', 'section' => 'consumption', 'from' => now()->subDay()->toDateString(), 'to' => now()->addDay()->toDateString()]))
            ->assertOk()->assertSee('data-consumption-row="'.$this->gel->id.'"', false)->assertSee('data-consumption-service="'.$this->service->id.'"', false)
            ->assertSeeInOrder(['data-consumption-row="'.$this->gel->id.'"', '0,1 flacon'], false);
    }

    public function test_the_estimated_cost_and_margin_come_from_the_recipe(): void
    {
        $estimate = app(ServiceConsumption::class)->estimate($this->service);

        // 0.05 x 40 + default mask 0.04 x 100 + 2 x 0.5 = 2 + 4 + 1 = 7 (the alternative is not counted twice).
        $this->assertEqualsWithDelta(7.0, $estimate['cost'], 0.001);
        $this->assertCount(4, $estimate['lines']);

        $this->actingAs($this->owner)->get(route('catalog', ['panel' => 'articles', 'edit' => $this->service->id]))
            ->assertOk()->assertSee('data-components-editor', false)->assertSee('Produits utilisés');
    }

    public function test_the_edit_endpoint_replaces_the_recipe_and_validates_it(): void
    {
        $this->app['auth']->forgetGuards();
        $call = fn (array $payload) => $this->withToken($this->token())
            ->withHeader('X-Tenant-Slug', $this->tenant->slug)->withHeader('X-Location-Id', (string) $this->location->id)
            ->putJson('/api/v1/items/'.$this->service->id.'/components', $payload);

        $ok = $call(['components' => [
            ['component_item_id' => $this->gel->id, 'quantity' => 6],
            ['component_item_id' => $this->hydratant->id, 'quantity' => 8, 'choice_group' => 'Masque', 'is_default' => true],
        ]])->assertOk();
        $this->assertCount(2, $ok->json('components'));
        $this->assertEqualsWithDelta(6.0, $ok->json('components.0.quantity'), 0.001);
        $this->assertEqualsWithDelta(0.03 * 40 + 0.032 * 100, $ok->json('estimated_cost'), 0.01);
        $this->assertSame(2, ItemComponent::where('item_id', $this->service->id)->count(), 'a row left out is removed');

        // A service is not a product; a variant must be the service's own; two rows for one product are one too many.
        $call(['components' => [['component_item_id' => $this->service->id, 'quantity' => 1]]])->assertStatus(422);
        $call(['components' => [['component_item_id' => $this->gel->id, 'quantity' => 1, 'variant_id' => 999999]]])->assertStatus(422);
        $call(['components' => [['component_item_id' => $this->gel->id, 'quantity' => 1], ['component_item_id' => $this->gel->id, 'quantity' => 2]]])->assertStatus(422);
        $call(['components' => [['component_item_id' => $this->gel->id, 'quantity' => 0]]])->assertStatus(422);

        $call(['components' => []])->assertOk();
        $this->assertSame(0, ItemComponent::where('item_id', $this->service->id)->count());
    }

    public function test_editing_a_recipe_needs_the_edit_right(): void
    {
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Caisse', 'key' => 'caisse', 'permissions' => ['sales.create', 'items.view']]);
        $user = User::create(['name' => 'Caisse', 'email' => 'caisse@example.test', 'password' => Hash::make('password')]);
        $this->tenant->users()->attach($user->id, ['role' => $role->key]);
        $token = $this->postJson('/api/v1/auth/login', ['email' => 'caisse@example.test', 'password' => 'password', 'device_name' => 'caisse'])->json('token');

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->withHeader('X-Tenant-Slug', $this->tenant->slug)->withHeader('X-Location-Id', (string) $this->location->id)
            ->putJson('/api/v1/items/'.$this->service->id.'/components', ['components' => []])
            ->assertForbidden();
        $this->assertSame(4, ItemComponent::where('item_id', $this->service->id)->count());
    }

    public function test_the_feeds_carry_capacities_recipes_and_fractional_stock(): void
    {
        $this->sell(['quantity' => 2])->assertCreated();
        $this->travel(3)->seconds(); // the feeds close their window on the previous second
        $headers = ['X-Tenant-Slug' => $this->tenant->slug, 'X-Location-Id' => (string) $this->location->id];

        $this->app['auth']->forgetGuards();
        $meta = $this->withToken($this->token())->withHeaders($headers)->getJson('/api/v1/sync/meta')->assertOk();
        $this->assertTrue($meta->json('item_components_is_full'));
        $rows = collect($meta->json('item_components'))->where('item_id', $this->service->id);
        $this->assertCount(4, $rows);
        $this->assertEqualsWithDelta(10.0, $rows->firstWhere('component_item_id', $this->gel->id)['quantity'], 0.001);
        $this->assertTrue((bool) $rows->firstWhere('component_item_id', $this->hydratant->id)['is_default']);
        $this->assertSame('ml', collect($meta->json('content_units'))->firstWhere('key', 'ml')['label']);

        $this->app['auth']->forgetGuards();
        $items = $this->withToken($this->token())->withHeaders($headers)->getJson('/api/v1/sync/items')->assertOk();
        $gel = collect($items->json('items'))->firstWhere('id', $this->gel->id);
        $this->assertEqualsWithDelta(200.0, (float) $gel['content_quantity'], 0.001);
        $this->assertSame('ml', $gel['content_unit']);
        $this->assertEqualsWithDelta(9.9, (float) $gel['stock_quantity'], 0.0001);

        $this->app['auth']->forgetGuards();
        $stock = $this->withToken($this->token())->withHeaders($headers)->getJson('/api/v1/sync/stock')->assertOk();
        $gelStock = collect($stock->json('stock'))->firstWhere('item_id', $this->gel->id);
        $this->assertEqualsWithDelta(9.9, $gelStock['qty'], 0.0001);
        $this->assertEqualsWithDelta(9.9, $gelStock['available'], 0.0001);
    }

    public function test_the_product_form_saves_a_capacity_both_or_neither(): void
    {
        $this->actingAs($this->owner);
        $form = fn (array $extra) => $this->put(route('catalog.items.update', $this->gloves), array_merge([
            'type' => 'supply', 'title' => 'Gants jetables', 'unit_id' => $this->gloves->unit_id ?? \App\Models\Unit::where('tenant_id', $this->tenant->id)->value('id'),
            'tax_id' => \App\Models\Tax::where('tenant_id', $this->tenant->id)->value('id'),
            'purchase_price' => 0.5, 'sale_price' => 0, 'stock_quantity' => 100, 'min_stock_threshold' => 1,
        ], $extra));

        $form(['content_quantity' => 100])->assertSessionHasErrors('content_unit');
        $form(['content_unit' => 'ml'])->assertSessionHasErrors('content_quantity');
        $form(['content_quantity' => 100, 'content_unit' => 'piece'])->assertSessionHasNoErrors();

        $fresh = $this->gloves->fresh();
        $this->assertEqualsWithDelta(100.0, (float) $fresh->content_quantity, 0.001);
        $this->assertSame('piece', $fresh->content_unit);
    }

    public function test_the_back_office_saves_a_recipe_from_the_form(): void
    {
        $this->actingAs($this->owner);
        $this->put(route('catalog.components.sync', $this->service), ['components' => [
            ['component_item_id' => $this->gel->id, 'quantity' => '5', 'choice_group' => '', 'is_default' => '0'],
            ['component_item_id' => $this->purifiant->id, 'quantity' => '12.5', 'choice_group' => 'Masque', 'is_default' => '1'],
        ]])->assertSessionHasNoErrors();

        $rows = ItemComponent::where('item_id', $this->service->id)->orderBy('sort_order')->get();
        $this->assertCount(2, $rows);
        $this->assertEqualsWithDelta(12.5, (float) $rows[1]->quantity, 0.001);
        $this->assertTrue($rows[1]->is_default);
        $this->assertNull($rows[0]->choice_group);
    }
    public function test_the_container_name_reads_in_the_display_helper(): void
    {
        app()->setLocale('fr');
        $this->gel->update(['content_container' => 'flacon']);
        $this->assertSame('3,45 flacons (690 ml)', \App\Support\ContentUnits::describeStock($this->gel->fresh(), 3.45));
        $this->assertSame('1 flacon (200 ml)', \App\Support\ContentUnits::describeStock($this->gel->fresh(), 1));
        $this->assertSame('0,5 flacon (100 ml)', \App\Support\ContentUnits::describeStock($this->gel->fresh(), 0.5));
        // No container: « unités » as ever.
        $this->assertSame('3 unités', \App\Support\ContentUnits::describeStock($this->gloves, 3));
        $this->assertSame(['key' => 'rouleau', 'one' => 'rouleau', 'many' => 'rouleaux'], [
            'key' => 'rouleau',
            'one' => \App\Support\ContentUnits::containerLabel('rouleau', 1),
            'many' => \App\Support\ContentUnits::containerLabel('rouleau', 2),
        ]);
    }

    public function test_the_container_rides_the_sync_payloads(): void
    {
        $this->gel->update(['content_container' => 'flacon']);
        $this->travel(3)->seconds();
        $headers = ['X-Tenant-Slug' => $this->tenant->slug, 'X-Location-Id' => (string) $this->location->id];

        $this->app['auth']->forgetGuards();
        $meta = $this->withToken($this->token())->withHeaders($headers)->getJson('/api/v1/sync/meta')->assertOk();
        $containers = collect($meta->json('content_containers'));
        $this->assertCount(9, $containers);
        $this->assertSame(['key' => 'flacon', 'label' => 'flacon', 'label_plural' => 'flacons'], $containers->firstWhere('key', 'flacon'));
        $this->assertSame('boîtes', $containers->firstWhere('key', 'boite')['label_plural']);

        $this->app['auth']->forgetGuards();
        $items = collect($this->withToken($this->token())->withHeaders($headers)->getJson('/api/v1/sync/items')->assertOk()->json('items'));
        $this->assertSame('flacon', $items->firstWhere('id', $this->gel->id)['content_container']);
        $this->assertNull($items->firstWhere('id', $this->gloves->id)['content_container']);
    }

    public function test_the_item_api_accepts_a_container_and_validates_it(): void
    {
        $unit = \App\Models\Unit::where('tenant_id', $this->tenant->id)->value('id');
        $tax = \App\Models\Tax::where('tenant_id', $this->tenant->id)->value('id');
        $payload = fn (array $extra) => array_merge([
            'local_id' => Str::uuid()->toString(), 'title' => 'Cire '.Str::random(4), 'sale_price' => 0, 'unit_id' => $unit, 'tax_id' => $tax, 'type' => 'supply',
        ], $extra);
        $token = $this->token();
        $post = function (array $body) use ($token) {
            $this->app['auth']->forgetGuards();

            return $this->withToken($token)->postJson('/api/v1/items', $body);
        };

        $post($payload(['content_container' => 'seau']))->assertUnprocessable()->assertJsonValidationErrors('content_container');
        // Alone, without a capacity.
        $created = $post($payload(['content_container' => 'pot']))->assertCreated();
        $this->assertSame('pot', Item::findOrFail($created->json('item.id'))->content_container);
        // With a capacity.
        $with = $post($payload(['content_container' => 'tube', 'content_quantity' => 50, 'content_unit' => 'ml']))->assertCreated();
        $this->assertSame('tube', Item::findOrFail($with->json('item.id'))->content_container);
        // A service never carries one.
        $svc = $post($payload(['type' => 'service', 'content_container' => 'pot']))->assertCreated();
        $this->assertNull(Item::findOrFail($svc->json('item.id'))->content_container);

        // Update: set, clear, refuse a stranger.
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->putJson('/api/v1/items/'.$this->gel->id, ['content_container' => 'flacon'])->assertOk();
        $this->assertSame('flacon', $this->gel->fresh()->content_container);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->putJson('/api/v1/items/'.$this->gel->id, ['content_container' => 'seau'])->assertUnprocessable();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->putJson('/api/v1/items/'.$this->gel->id, ['content_container' => null])->assertOk();
        $this->assertNull($this->gel->fresh()->content_container);
    }

    public function test_the_product_form_saves_a_container(): void
    {
        $this->actingAs($this->owner);
        $form = fn (array $extra) => $this->put(route('catalog.items.update', $this->gloves), array_merge([
            'type' => 'supply', 'title' => 'Gants jetables', 'unit_id' => \App\Models\Unit::where('tenant_id', $this->tenant->id)->value('id'),
            'tax_id' => \App\Models\Tax::where('tenant_id', $this->tenant->id)->value('id'),
            'purchase_price' => 0.5, 'sale_price' => 0, 'stock_quantity' => 100, 'min_stock_threshold' => 1,
        ], $extra));

        $form(['content_container' => 'seau'])->assertSessionHasErrors('content_container');
        $form(['content_container' => 'boite'])->assertSessionHasNoErrors();
        $this->assertSame('boite', $this->gloves->fresh()->content_container);
        $form(['content_container' => ''])->assertSessionHasNoErrors();
        $this->assertNull($this->gloves->fresh()->content_container);
    }
    public function test_the_adjustment_api_takes_fractions_and_keeps_whole_stocks_whole(): void
    {
        $token = $this->token();
        $headers = ['X-Tenant-Slug' => $this->tenant->slug, 'X-Location-Id' => (string) $this->location->id];
        $adjust = function (array $body) use ($token, $headers) {
            $this->app['auth']->forgetGuards();

            return $this->withToken($token)->withHeaders($headers)->postJson('/api/v1/inventory/adjustments', $body + ['idempotency_key' => Str::uuid()->toString(), 'item_id' => $this->gel->id]);
        };

        // 10 bottles - 2.5 = 7.5
        $out = $adjust(['mode' => 'delta', 'quantity' => -2.5])->assertCreated();
        $this->assertEqualsWithDelta(7.5, $this->onHand($this->gel), 0.0001);
        $this->assertSame(7.5, $out->json('stock_after.quantity'));
        // + 0.0001 is the finest step; 5 decimals are refused.
        $adjust(['mode' => 'delta', 'quantity' => 0.00001])->assertUnprocessable();
        $adjust(['mode' => 'delta', 'quantity' => 0.25])->assertCreated();
        $this->assertEqualsWithDelta(7.75, $this->onHand($this->gel), 0.0001);
        // Correction to a fraction, then to a whole number: the JSON stays an integer.
        $adjust(['mode' => 'correction', 'quantity' => 3.45])->assertCreated();
        $this->assertEqualsWithDelta(3.45, (float) $this->gel->fresh()->stock_quantity, 0.0001);
        $whole = $adjust(['mode' => 'correction', 'quantity' => 4])->assertCreated();
        $this->assertSame(4, $whole->json('stock_after.quantity'));
        $this->assertSame(4, $this->gel->fresh()->stock_quantity);
        // Same figure again: nothing to do.
        $adjust(['mode' => 'correction', 'quantity' => 4])->assertCreated()->assertJsonPath('no_change', true);
        // Removing more than there is stays refused.
        $adjust(['mode' => 'delta', 'quantity' => -4.5])->assertUnprocessable();
    }

    public function test_the_web_adjustment_and_stocktake_take_fractions(): void
    {
        $this->actingAs($this->owner);
        $this->post(route('catalog.stock-adjustments.store'), [
            'reason' => 'Casse', 'items' => [['item_id' => $this->gel->id, 'direction' => 'remove', 'quantity' => '2.5']],
        ])->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(7.5, (float) $this->gel->fresh()->stock_quantity, 0.0001);
        $this->assertEqualsWithDelta(7.5, $this->onHand($this->gel), 0.0001);
        $adjustment = \App\Models\StockAdjustment::latest('id')->firstOrFail();
        $this->assertEqualsWithDelta(2.5, (float) $adjustment->total_quantity, 0.0001);
        $this->assertEqualsWithDelta(-2.5, (float) $adjustment->lines[0]['quantity_delta'], 0.0001);

        $this->post(route('catalog.stock-adjustments.store'), [
            'reason' => 'Réception', 'items' => [['item_id' => $this->gel->id, 'direction' => 'add', 'quantity' => '0.45']],
        ])->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(7.95, (float) $this->gel->fresh()->stock_quantity, 0.0001);

        // « set » to a fraction (the stock correction), then back to a whole.
        $this->post(route('catalog.stock-adjustments.store'), [
            'reason' => 'Comptage', 'items' => [['item_id' => $this->gel->id, 'direction' => 'set', 'quantity' => '3.45']],
        ])->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(3.45, $this->onHand($this->gel), 0.0001);
        $this->post(route('catalog.stock-adjustments.store'), [
            'reason' => 'Comptage', 'items' => [['item_id' => $this->gel->id, 'direction' => 'set', 'quantity' => '5']],
        ])->assertSessionHasNoErrors();
        $this->assertSame(5, $this->gel->fresh()->stock_quantity);

        // Five decimals are refused.
        $this->post(route('catalog.stock-adjustments.store'), [
            'reason' => 'x', 'items' => [['item_id' => $this->gel->id, 'direction' => 'add', 'quantity' => '0.00001']],
        ])->assertSessionHasErrors('items.0.quantity');

        // A stocktake counts 2.95 bottles against the 5 expected.
        $this->post(route('catalog.stocktakes.store'), [
            'location_id' => $this->location->id, 'items' => [['item_id' => $this->gel->id, 'counted_quantity' => '']],
        ])->assertSessionHasNoErrors();
        $stocktake = \App\Models\Stocktake::firstOrFail();
        $line = $stocktake->items()->firstOrFail();
        $this->assertSame(5, $line->expected_quantity);
        $this->post(route('catalog.stocktakes.counts.update', $stocktake), ['counts' => [$line->id => '2.95']])->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(2.95, (float) $line->fresh()->counted_quantity, 0.0001);
        $this->assertEqualsWithDelta(-2.05, $line->fresh()->difference(), 0.0001);
        $this->post(route('catalog.stocktakes.complete', $stocktake), ['_idempotency_key' => 'fraction-count'])->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(2.95, (float) $this->gel->fresh()->stock_quantity, 0.0001);
        $this->assertEqualsWithDelta(2.95, $this->onHand($this->gel), 0.0001);
    }
    public function test_the_web_till_adjusts_a_quantity_and_zeroes_a_product(): void
    {
        $this->actingAs($this->owner);
        $this->post(route('pos.store'), [
            'cart' => json_encode([[
                'id' => $this->service->id, 'quantity' => 1,
                'component_choices' => [['group' => 'Masque', 'component_item_id' => $this->purifiant->id]],
                'component_overrides' => [
                    ['component_item_id' => $this->gel->id, 'quantity' => 30],      // 30 ml instead of 10
                    ['component_item_id' => $this->gloves->id, 'quantity' => 0],    // none this time
                ],
            ]]),
            'cash_amount' => 1000,
        ])->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(9.85, $this->onHand($this->gel), 0.0001, '30 ml of 200');
        $this->assertEqualsWithDelta(100.0, $this->onHand($this->gloves), 0.0001, 'zeroed: nothing deducted');
        $this->assertEqualsWithDelta(3.96, $this->onHand($this->purifiant), 0.0001);
        $this->assertEqualsWithDelta(4.0, $this->onHand($this->hydratant), 0.0001, 'the default mask is untouched');
        $line = SaleItem::where('item_id', $this->service->id)->sole();
        $this->assertSame(2, SaleItemConsumption::where('sale_item_id', $line->id)->count());
        $this->assertEqualsWithDelta(0.15, (float) SaleItemConsumption::where('sale_item_id', $line->id)->where('component_item_id', $this->gel->id)->value('quantity_stock'), 0.0001);
        $this->assertEqualsWithDelta(30.0, (float) SaleItemConsumption::where('sale_item_id', $line->id)->where('component_item_id', $this->gel->id)->value('quantity_content'), 0.0001);
    }

    public function test_the_till_page_embeds_the_recipes_and_capacities_for_the_choice_dialog(): void
    {
        $this->actingAs($this->owner);
        $html = $this->get(route('pos'))->assertOk()->getContent();
        $this->assertStringContainsString('pos-consumption-dialog', $html);
        $this->assertSame(1, preg_match('#<script type="application/json" class="pos-consumption-data">(.*?)</script>#s', $html, $m));
        $data = json_decode($m[1], true);

        $rows = collect($data['recipes'][$this->service->id] ?? []);
        $this->assertCount(4, $rows);
        $masks = $rows->where('group', 'Masque');
        $this->assertCount(2, $masks, 'two alternatives: the till must ask');
        $this->assertSame([$this->hydratant->id], $masks->where('is_default', true)->pluck('product_id')->values()->all());
        $this->assertEqualsWithDelta(10.0, $rows->firstWhere('product_id', $this->gel->id)['quantity'], 0.001);
        // Products: their name and what the quantity is counted in.
        $this->assertSame('Gel nettoyant', $data['products'][$this->gel->id]['name']);
        $this->assertSame('ml', $data['products'][$this->gel->id]['unit']);
        $this->assertSame('', $data['products'][$this->gloves->id]['unit'], 'no capacity: counted in stocked units');
        // A quantity of 0 in the recipe never reaches the till.
        ItemComponent::where('item_id', $this->service->id)->where('component_item_id', $this->gloves->id)->update(['quantity' => 0]);
        $again = $this->get(route('pos'))->getContent();
        preg_match('#<script type="application/json" class="pos-consumption-data">(.*?)</script>#s', $again, $m2);
        $this->assertCount(3, json_decode($m2[1], true)['recipes'][$this->service->id]);
    }

    public function test_a_held_ticket_keeps_the_chosen_alternative_and_adjustments(): void
    {
        $this->actingAs($this->owner);
        $this->post(route('pos.tickets.store'), [
            'cart' => json_encode([[
                'id' => $this->service->id, 'quantity' => 1,
                'component_choices' => [['group' => 'Masque', 'component_item_id' => $this->purifiant->id]],
                'component_overrides' => [['component_item_id' => $this->gel->id, 'quantity' => 30]],
            ]]),
        ])->assertSessionHasNoErrors();

        $ticket = \App\Models\PosTicket::firstOrFail();
        $line = $ticket->cart[0];
        $this->assertSame($this->purifiant->id, (int) $line['component_choices'][0]['component_item_id']);
        $this->assertEqualsWithDelta(30.0, (float) $line['component_overrides'][0]['quantity'], 0.001);
    }

    /**
     * Seen on a salon's journal: three consumption lines all reading « 0 /
     * 0 / 0,00 DH ». The quantities were cast to whole numbers, and a product
     * with no stock recorded no cost on its movement.
     */
    public function test_the_stock_journal_shows_fractional_consumption_and_its_cost_even_with_no_stock(): void
    {
        $empty = $this->product('Cire chaude', 500, 'g', 60, 0);
        $this->recipe([['component_item_id' => $empty->id, 'quantity' => 8]]);
        $this->sell()->assertCreated();

        $movement = \DB::table('stock_movements')->where('item_id', $empty->id)->where('type', 'service_consumption')->first();
        $this->assertEquals(-0.016, (float) $movement->quantity_delta);
        $this->assertEquals(0.96, (float) $movement->total_cost, '8 g of a 60 DH pot of 500 g');
        $this->assertStringContainsString('sans stock', $movement->note);

        $page = $this->actingAs($this->owner)->get(route('stock', ['panel' => 'stock-adjustments']))->assertOk()->getContent();
        $this->assertStringContainsString('Consommation interne', $page);
        $this->assertStringNotContainsString('service_consumption</span>', $page, 'a label, not the raw type');
        $this->assertStringContainsString('-0,05', $page, '10 ml of a 200 ml gel');
        $this->assertStringContainsString('(10 ml)', $page);
        $this->assertStringContainsString('0,96', $page);
        $this->assertStringNotContainsString('-0,96', $page, 'an empty shelf is worth 0, never less');
    }

    // ── Negative stock: the shelf shows its real value ──────────────────────

    private function receive(Item $item, float $quantity, float $unitCost, string $key): array
    {
        return app(InventoryLedgerService::class)->createIncomingMovement([
            'tenantId' => $this->tenant->id, 'itemId' => $item->id, 'variantId' => null,
            'locationId' => $this->location->id, 'type' => InventoryMovementType::PURCHASE,
            'quantity' => $quantity, 'unitCost' => $unitCost, 'occurredAt' => now()->addMinute(),
            'syncedAt' => null, 'userId' => null, 'idempotencyKey' => $key,
            'referenceType' => null, 'referenceId' => null, 'referenceNumber' => null, 'reason' => null,
            'note' => null, 'virtualDeviceId' => null, 'actorNameSnapshot' => null, 'terminalNameSnapshot' => null,
        ]);
    }

    public function test_consuming_from_an_empty_shelf_shows_the_real_negative_stock_everywhere(): void
    {
        $cire = $this->product('Cire chaude', 500, 'g', 60, 0);
        $this->recipe([['component_item_id' => $cire->id, 'quantity' => 8]]); // 0.016 pot
        $this->sell()->assertCreated();

        $movement = InventoryMovement::where('item_id', $cire->id)->where('type', 'service_consumption')->sole();
        $this->assertEqualsWithDelta(-0.016, (float) $movement->quantity_after, 0.00001);
        $this->assertEqualsWithDelta(-0.016, $this->onHand($cire), 0.00001);
        $this->assertEqualsWithDelta(-0.016, (float) $cire->fresh()->stock_quantity, 0.00001);
        $this->assertEqualsWithDelta(0.016, (float) ItemLocationStock::where('item_id', $cire->id)->value('deficit_quantity'), 0.00001);
        $ledger = app(InventoryLedgerService::class);
        $this->assertSame(0.0, $ledger->availableQuantity($this->tenant->id, $cire->id, null, $this->location->id), 'selling availability clamps at 0');
        $this->assertSame(0.0, $ledger->inventoryValue($this->tenant->id, $cire->id, $this->location->id), 'a deficit is never a negative value');

        // The feeds carry the negative figure, availability stays at 0.
        $headers = ['X-Tenant-Slug' => $this->tenant->slug, 'X-Location-Id' => (string) $this->location->id];
        $this->travel(3)->seconds();
        $this->app['auth']->forgetGuards();
        $stock = $this->withToken($this->token())->withHeaders($headers)->getJson('/api/v1/sync/stock')->assertOk();
        $row = collect($stock->json('stock'))->firstWhere('item_id', $cire->id);
        $this->assertEqualsWithDelta(-0.016, $row['qty'], 0.00001);
        $this->assertEquals(0, $row['available']);
        $this->app['auth']->forgetGuards();
        $items = $this->withToken($this->token())->withHeaders($headers)->getJson('/api/v1/sync/items')->assertOk();
        $this->assertEqualsWithDelta(-0.016, (float) collect($items->json('items'))->firstWhere('id', $cire->id)['stock_quantity'], 0.00001);

        // The back-office list: « Stock négatif », distinct from « Rupture ».
        $negative = $this->actingAs($this->owner)->get(route('stock', ['panel' => 'stock-adjustments', 'stock_inventory_state' => 'negative']))->assertOk()->getContent();
        $this->assertStringContainsString('Cire chaude', $negative);
        $this->assertStringContainsString('-0,016', $negative);
        $this->assertStringContainsString('Stock négatif</span>', $negative);
        $out = $this->actingAs($this->owner)->get(route('stock', ['panel' => 'stock-adjustments', 'stock_inventory_state' => 'out']))->assertOk()->getContent();
        $this->assertStringNotContainsString('Stock négatif</span>', $out, 'a negative stock is not a plain « Rupture »');
    }

    public function test_incoming_stock_pays_the_deficit_first_and_trues_up_the_cost(): void
    {
        $cire = $this->product('Cire chaude', 500, 'g', 60, 0);
        $this->recipe([['component_item_id' => $cire->id, 'quantity' => 8]]);
        $this->sell()->assertCreated();

        $consumed = SaleItemConsumption::where('component_item_id', $cire->id)->sole();
        $line = SaleItem::findOrFail($consumed->sale_item_id);
        $sale = Sale::findOrFail($consumed->sale_id);
        $before = (float) $sale->cogs;
        $this->assertEqualsWithDelta(0.96, (float) SaleItemConsumption::where('component_item_id', $cire->id)->sole()->total_cost, 0.0001, 'booked at the purchase price');

        // One box arrives, bought at 80 (not 60).
        $result = $this->receive($cire, 1, 80, 'box-1');

        $this->assertEqualsWithDelta(0.984, $this->onHand($cire), 0.00001, '-0.016 then +1');
        $this->assertEqualsWithDelta(0.984, (float) $result['layer']->remaining_quantity, 0.00001);
        $this->assertEqualsWithDelta(0.984, (float) $result['layer']->original_quantity, 0.00001);
        $this->assertEqualsWithDelta(0.0, (float) ItemLocationStock::where('item_id', $cire->id)->value('deficit_quantity'), 0.00001);
        $this->assertEqualsWithDelta(0.984, (float) $cire->fresh()->stock_quantity, 0.00001);
        $ledger = app(InventoryLedgerService::class);
        $this->assertEqualsWithDelta(0.984 * 80, $ledger->inventoryValue($this->tenant->id, $cire->id, $this->location->id), 0.001, 'the settled part is not shelf value');

        // COGS trued up with the real cost: 0.016 x (80 - 60) = 0.32 more.
        $consumption = SaleItemConsumption::where('component_item_id', $cire->id)->sole();
        $this->assertEqualsWithDelta(1.28, (float) $consumption->total_cost, 0.0001);
        $this->assertEqualsWithDelta(1.28, (float) InventoryMovement::where('type', 'service_consumption')->where('item_id', $cire->id)->value('total_cost'), 0.0001);
        $this->assertEqualsWithDelta($before + 0.32, (float) $sale->fresh()->cogs, 0.0001);
        $this->assertEqualsWithDelta((float) $line->total_cost + 0.32, (float) $line->fresh()->total_cost, 0.0001);
        $this->assertEqualsWithDelta((float) $sale->fresh()->cogs, (float) SaleItem::where('sale_id', $sale->id)->sum('total_cost'), 0.0001, 'sale and lines stay coherent');

        // A second box never settles twice.
        $this->receive($cire, 1, 80, 'box-2');
        $this->assertEqualsWithDelta(1.984, $this->onHand($cire), 0.00001);
        $this->assertEqualsWithDelta(1.28, (float) SaleItemConsumption::where('component_item_id', $cire->id)->sole()->total_cost, 0.0001);
    }

    public function test_an_oversold_product_goes_negative_only_when_the_shop_allows_it(): void
    {
        $mug = $this->product('Mug', null, null, 5, 0);
        $this->service->update(['type' => 'supply', 'sale_price' => 20]);
        $this->recipe([], $this->service);
        ItemComponent::where('item_id', $this->service->id)->delete();

        // Overselling off: still refused, the stock untouched.
        $refused = $this->sell(['item_id' => $mug->id, 'quantity' => 2]);
        $this->assertFalse($refused->isSuccessful());
        $this->assertEquals(0, $this->onHand($mug));

        $settings = $this->tenant->settings;
        data_set($settings, 'pos.allow_oversell', true);
        $this->tenant->update(['settings' => $settings]);

        $this->sell(['item_id' => $mug->id, 'quantity' => 2])->assertCreated();
        $this->assertEquals(-2, $this->onHand($mug));
        $this->assertEquals(-2, (float) $mug->fresh()->stock_quantity);
        $this->assertEquals(-2, (float) InventoryMovement::where('item_id', $mug->id)->where('type', 'sale')->value('quantity_after'));

        // Stock in: -2 then +5 = 3, the layer holds 3, the sale is trued up at the real cost.
        $line = SaleItem::where('item_id', $mug->id)->sole();
        $this->receive($mug, 5, 7, 'mugs');
        $this->assertEquals(3, $this->onHand($mug));
        $this->assertEquals(3, (float) \App\Models\InventoryLayer::where('item_id', $mug->id)->sum('remaining_quantity'));
        $this->assertEqualsWithDelta(14.0, (float) $line->fresh()->total_cost, 0.0001, '2 x 7 booked on the sale line');
    }

    public function test_the_backfill_corrects_empty_shelves_with_a_recorded_shortfall_once(): void
    {
        $cire = $this->product('Cire chaude', 500, 'g', 60, 0);
        $this->recipe([['component_item_id' => $cire->id, 'quantity' => 8]]);
        $this->sell()->assertCreated();

        // Put the data back as the old code left it: shelf at 0, nothing tracked.
        ItemLocationStock::where('item_id', $cire->id)->update(['quantity' => 0, 'deficit_quantity' => 0]);
        $cire->update(['stock_quantity' => 0]);
        InventoryMovement::where('item_id', $cire->id)->update(['deficit_quantity' => 0]);

        $migration = require database_path('migrations/2026_11_24_000002_backfill_negative_stock_from_shortfalls.php');
        $migration->up();
        $migration->up();

        $this->assertEqualsWithDelta(-0.016, $this->onHand($cire), 0.00001, 'corrected once, not twice');
        $this->assertEqualsWithDelta(0.016, (float) ItemLocationStock::where('item_id', $cire->id)->value('deficit_quantity'), 0.00001);
        $this->assertEqualsWithDelta(-0.016, (float) $cire->fresh()->stock_quantity, 0.00001);
    }
}
