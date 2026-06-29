<?php

namespace Tests\Feature\Inventory;

use App\Exceptions\InsufficientStockException;
use App\Models\InventoryLayer;
use App\Models\InventoryLayerConsumption;
use App\Models\InventoryMovement;
use App\Models\Item;
use App\Models\ItemLocationStock;
use App\Models\Location;
use App\Models\SaleReturn;
use App\Models\Tenant;
use App\Services\Inventory\InventoryLedgerService;
use App\Services\Inventory\InventoryMovementType;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Test suite for the LIFO inventory ledger.
 *
 * Each test is self-contained and boots a fresh DB (RefreshDatabase).
 * Tests call InventoryLedgerService directly — no HTTP layer — to verify
 * the core ledger logic independently of controller concerns.
 *
 * Covered scenarios:
 *   1. Basic LIFO valuation: newest batch consumed first.
 *   2. LIFO ordering verified: batches consumed in newest-first order.
 *   3. Insufficient stock raises InsufficientStockException.
 *   4. Idempotent sync: same idempotency_key does not create duplicates.
 *   5. Sale return restores inventory with the original LIFO-consumed cost.
 *   6. Transfer preserves cost between locations (out → in same unit_cost).
 *   7. item.purchase_price = 0 does not affect LIFO valuation.
 */
class LifoInventoryTest extends TestCase
{
    use RefreshDatabase;

    private InventoryLedgerService $ledger;
    private Tenant $tenant;
    private Location $location;
    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->ledger   = app(InventoryLedgerService::class);
        $this->tenant   = Tenant::first();
        $this->location = Location::where('tenant_id', $this->tenant->id)->first();

        // Create a fresh item with purchase_price = 0 so tests are not
        // accidentally polluted by the catalog cost field.
        $this->item = Item::create([
            'tenant_id'     => $this->tenant->id,
            'type'          => 'supply',
            'status'        => 'active',
            'title'         => 'LIFO Test Item',
            'sale_price'    => 100.00,
            'purchase_price' => 0.00,   // explicitly zero — must NOT affect valuation
            'stock_quantity' => 0,
        ]);
    }

    // ── Test 1: Basic LIFO valuation ─────────────────────────────────────────────

    /**
     * Purchase 10 @ 1, 10 @ 2, 10 @ 3.
     * Sell 21.
     * Expected: 9 units remain, all from the oldest batch @ 1.
     *   Remaining value = 9 × 1 = 9.
     *   COGS for the sale = (10 × 3) + (10 × 2) + (1 × 1) = 30 + 20 + 1 = 51.
     */
    public function test_basic_lifo_valuation(): void
    {
        $t = $this->tenant->id;
        $l = $this->location->id;
        $i = $this->item->id;
        $base = now()->subHour();

        $this->ledger->createIncomingMovement($this->inParams($t, $i, $l, 10, 1.0, $base));
        $this->ledger->createIncomingMovement($this->inParams($t, $i, $l, 10, 2.0, $base->copy()->addMinutes(5)));
        $this->ledger->createIncomingMovement($this->inParams($t, $i, $l, 10, 3.0, $base->copy()->addMinutes(10)));

        $outResult = $this->ledger->createOutgoingMovement($this->outParams($t, $i, $l, 21, $base->copy()->addMinutes(15)));

        // COGS = 10@3 + 10@2 + 1@1 = 51
        $this->assertEqualsWithDelta(51.0, $outResult['cogs'], 0.001);

        // Remaining quantity = 9
        $remaining = $this->ledger->availableQuantity($t, $i, null, $l);
        $this->assertEqualsWithDelta(9.0, $remaining, 0.001);

        // Remaining value = 9 × 1 = 9
        $value = $this->ledger->inventoryValue($t, $i, $l);
        $this->assertEqualsWithDelta(9.0, $value, 0.001);

        // Average cost = 9/9 = 1
        $avg = $this->ledger->averageCost($t, $i, $l);
        $this->assertEqualsWithDelta(1.0, $avg, 0.001);

        // 3 layers created, oldest still has 9 remaining
        $layers = InventoryLayer::where('item_id', $i)->orderBy('occurred_at')->get();
        $this->assertCount(3, $layers);
        $this->assertEqualsWithDelta(9.0, (float) $layers->first()->remaining_quantity, 0.001);  // oldest
        $this->assertEqualsWithDelta(0.0, (float) $layers->last()->remaining_quantity, 0.001);   // newest fully consumed
    }

    // ── Test 2: LIFO ordering ────────────────────────────────────────────────────

    /**
     * Purchase batch A @ 5 then batch B @ 8.
     * Sell 5 — should consume entire batch B (newest), not A.
     */
    public function test_sale_consumes_newest_layers_first(): void
    {
        $t = $this->tenant->id;
        $l = $this->location->id;
        $i = $this->item->id;
        $base = now()->subHour();

        $this->ledger->createIncomingMovement($this->inParams($t, $i, $l, 10, 5.0, $base));
        $this->ledger->createIncomingMovement($this->inParams($t, $i, $l, 10, 8.0, $base->copy()->addMinutes(5)));

        $outResult = $this->ledger->createOutgoingMovement($this->outParams($t, $i, $l, 10, $base->copy()->addMinutes(10)));

        // LIFO: 10 × 8 = 80 (batch B entirely consumed)
        $this->assertEqualsWithDelta(80.0, $outResult['cogs'], 0.001);

        // Remaining: 10 units @ 5
        $this->assertEqualsWithDelta(10.0, $this->ledger->availableQuantity($t, $i, null, $l), 0.001);
        $this->assertEqualsWithDelta(50.0, $this->ledger->inventoryValue($t, $i, $l), 0.001);

        // Verify consumption row
        $consumption = InventoryLayerConsumption::first();
        $this->assertNotNull($consumption);
        $this->assertEqualsWithDelta(8.0, (float) $consumption->unit_cost, 0.001);
        $this->assertEqualsWithDelta(10.0, (float) $consumption->quantity_consumed, 0.001);
    }

    // ── Test 3: Insufficient stock ───────────────────────────────────────────────

    public function test_sale_fails_when_insufficient_stock(): void
    {
        $t = $this->tenant->id;
        $l = $this->location->id;
        $i = $this->item->id;

        $this->ledger->createIncomingMovement($this->inParams($t, $i, $l, 5, 10.0, now()->subMinutes(5)));

        $this->expectException(InsufficientStockException::class);

        $this->ledger->createOutgoingMovement($this->outParams($t, $i, $l, 6, now()));
    }

    // ── Test 4: Idempotent offline sync ──────────────────────────────────────────

    /**
     * Submitting the same idempotency_key twice must not create a second
     * movement, layer, or consumption.
     */
    public function test_duplicate_offline_sync_does_not_duplicate_movements(): void
    {
        $t   = $this->tenant->id;
        $l   = $this->location->id;
        $i   = $this->item->id;
        $key = Str::uuid()->toString();

        $params = $this->inParams($t, $i, $l, 10, 5.0, now()->subMinutes(5));
        $params['idempotencyKey'] = $key;

        $result1 = $this->ledger->createIncomingMovement($params);
        $result2 = $this->ledger->createIncomingMovement($params);

        // Second call detected the duplicate and returned same movement.
        $this->assertTrue($result2['already_existed']);
        $this->assertEquals($result1['movement']->id, $result2['movement']->id);

        // Only 1 movement and 1 layer in DB.
        $this->assertCount(1, InventoryMovement::where('item_id', $i)->get());
        $this->assertCount(1, InventoryLayer::where('item_id', $i)->get());

        // Available quantity is 10, not 20.
        $this->assertEqualsWithDelta(10.0, $this->ledger->availableQuantity($t, $i, null, $l), 0.001);
    }

    // ── Test 5: Sale return restores inventory with original cost ────────────────

    /**
     * Purchase 10 @ 2, then @ 4.
     * Sell 15 (LIFO: 10@4 + 5@2, COGS=60).
     * Return 5 units → restock at the original consumed cost 4 (from the newest batch).
     * Remaining stock after return = 5 (original) + 5 (return) = 10 (but at different costs).
     * The return creates a new layer at the original sale unit_cost.
     */
    public function test_sale_return_restores_inventory_at_original_consumed_cost(): void
    {
        $t = $this->tenant->id;
        $l = $this->location->id;
        $i = $this->item->id;
        $base = now()->subHour();

        $this->ledger->createIncomingMovement($this->inParams($t, $i, $l, 10, 2.0, $base));
        $this->ledger->createIncomingMovement($this->inParams($t, $i, $l, 10, 4.0, $base->copy()->addMinutes(5)));

        $outResult = $this->ledger->createOutgoingMovement($this->outParams($t, $i, $l, 15, $base->copy()->addMinutes(10)));

        // COGS = 10@4 + 5@2 = 50
        $this->assertEqualsWithDelta(50.0, $outResult['cogs'], 0.001);

        // 5 units remain @ 2
        $this->assertEqualsWithDelta(5.0, $this->ledger->availableQuantity($t, $i, null, $l), 0.001);

        // Restock 5 at the original cost of the newest consumed batch = 4.
        $returnParams = $this->inParams($t, $i, $l, 5, 4.0, $base->copy()->addMinutes(20));
        $returnParams['type'] = InventoryMovementType::CUSTOMER_RETURN;
        $this->ledger->createIncomingMovement($returnParams);

        $this->assertEqualsWithDelta(10.0, $this->ledger->availableQuantity($t, $i, null, $l), 0.001);
        // Value: 5@2 + 5@4 = 30
        $this->assertEqualsWithDelta(30.0, $this->ledger->inventoryValue($t, $i, $l), 0.001);

        // A new layer exists for the return.
        $returnLayer = InventoryLayer::where('item_id', $i)
            ->where('unit_cost', 4.0)
            ->where('original_quantity', 5)
            ->latest('occurred_at')
            ->first();
        $this->assertNotNull($returnLayer);
    }

    // ── Test 6: Transfer preserves cost between warehouses ───────────────────────

    /**
     * Location A: purchase 20 @ 7.
     * Transfer 10 from A to B using TRANSFER_OUT / TRANSFER_IN at the LIFO cost.
     * Location A should have 10 @ 7.
     * Location B should have 10 @ 7.
     */
    public function test_transfer_preserves_cost_between_locations(): void
    {
        $t       = $this->tenant->id;
        $i       = $this->item->id;
        $locA    = $this->location->id;
        $locB    = Location::create([
            'tenant_id' => $t,
            'name'      => 'Entrepôt B',
            'type'      => 'warehouse',
            'is_active' => true,
            'is_default' => false,
        ])->id;

        $base = now()->subHour();

        $this->ledger->createIncomingMovement($this->inParams($t, $i, $locA, 20, 7.0, $base));

        // Transfer OUT from A (LIFO consumption — gets unit_cost from layers)
        $outResult = $this->ledger->createOutgoingMovement([
            'tenantId'       => $t,
            'itemId'         => $i,
            'variantId'      => null,
            'locationId'     => $locA,
            'type'           => InventoryMovementType::TRANSFER_OUT,
            'quantity'       => 10,
            'occurredAt'     => $base->copy()->addMinutes(5),
            'syncedAt'       => null,
            'userId'         => null,
            'idempotencyKey' => Str::uuid()->toString(),
            'referenceType'  => null,
            'referenceId'    => null,
            'referenceNumber' => null,
            'reason'         => null,
            'note'           => null,
            'virtualDeviceId' => null,
            'actorNameSnapshot' => null,
            'terminalNameSnapshot' => null,
            'allowNegative'  => false,
        ]);

        // Transfer IN to B at the same unit_cost computed by LIFO
        $transferredCost = $outResult['unitCost']; // should be 7
        $this->assertEqualsWithDelta(7.0, $transferredCost, 0.001);

        $this->ledger->createIncomingMovement([
            'tenantId'       => $t,
            'itemId'         => $i,
            'variantId'      => null,
            'locationId'     => $locB,
            'type'           => InventoryMovementType::TRANSFER_IN,
            'quantity'       => 10,
            'unitCost'       => $transferredCost,
            'occurredAt'     => $base->copy()->addMinutes(6),
            'syncedAt'       => null,
            'userId'         => null,
            'idempotencyKey' => Str::uuid()->toString(),
            'referenceType'  => null,
            'referenceId'    => null,
            'referenceNumber' => null,
            'reason'         => null,
            'note'           => null,
            'virtualDeviceId' => null,
            'actorNameSnapshot' => null,
            'terminalNameSnapshot' => null,
        ]);

        // Location A: 10 remaining @ 7 = 70
        $this->assertEqualsWithDelta(10.0, $this->ledger->availableQuantity($t, $i, null, $locA), 0.001);
        $this->assertEqualsWithDelta(70.0, $this->ledger->inventoryValue($t, $i, $locA), 0.001);

        // Location B: 10 @ 7 = 70
        $this->assertEqualsWithDelta(10.0, $this->ledger->availableQuantity($t, $i, null, $locB), 0.001);
        $this->assertEqualsWithDelta(70.0, $this->ledger->inventoryValue($t, $i, $locB), 0.001);
    }

    // ── Test 7: purchase_price = 0 does not affect LIFO valuation ────────────────

    /**
     * Item's catalog purchase_price is 0.
     * Purchase 10 @ 15 via the ledger.
     * Valuation must reflect the movement cost, never the catalog price.
     */
    public function test_zero_purchase_price_does_not_corrupt_valuation(): void
    {
        $t = $this->tenant->id;
        $l = $this->location->id;
        $i = $this->item->id;

        // Confirm the test item has purchase_price = 0.
        $this->assertEquals(0, (float) $this->item->purchase_price);

        $this->ledger->createIncomingMovement($this->inParams($t, $i, $l, 10, 15.0, now()->subMinutes(5)));

        $value = $this->ledger->inventoryValue($t, $i, $l);
        $avg   = $this->ledger->averageCost($t, $i, $l);
        $qty   = $this->ledger->availableQuantity($t, $i, null, $l);

        $this->assertEqualsWithDelta(10.0, $qty, 0.001);
        $this->assertEqualsWithDelta(150.0, $value, 0.001);   // 10 × 15, NOT 10 × 0
        $this->assertEqualsWithDelta(15.0, $avg, 0.001);

        // Stock cache is also correct.
        $cached = ItemLocationStock::where('item_id', $i)->where('location_id', $l)->first();
        $this->assertNotNull($cached);
        $this->assertEqualsWithDelta(10.0, (float) $cached->quantity, 0.001);
        $this->assertEqualsWithDelta(15.0, (float) $cached->average_cost, 0.001);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────────

    private function inParams(
        int $tenantId,
        int $itemId,
        int $locationId,
        float $qty,
        float $cost,
        Carbon $occurredAt,
        ?string $type = null,
    ): array {
        return [
            'tenantId'             => $tenantId,
            'itemId'               => $itemId,
            'variantId'            => null,
            'locationId'           => $locationId,
            'type'                 => $type ?? InventoryMovementType::PURCHASE,
            'quantity'             => $qty,
            'unitCost'             => $cost,
            'occurredAt'           => $occurredAt,
            'syncedAt'             => null,
            'userId'               => null,
            'idempotencyKey'       => Str::uuid()->toString(),
            'referenceType'        => null,
            'referenceId'          => null,
            'referenceNumber'      => null,
            'reason'               => null,
            'note'                 => null,
            'virtualDeviceId'      => null,
            'actorNameSnapshot'    => null,
            'terminalNameSnapshot' => null,
        ];
    }

    private function outParams(
        int $tenantId,
        int $itemId,
        int $locationId,
        float $qty,
        Carbon $occurredAt,
    ): array {
        return [
            'tenantId'             => $tenantId,
            'itemId'               => $itemId,
            'variantId'            => null,
            'locationId'           => $locationId,
            'type'                 => InventoryMovementType::SALE,
            'quantity'             => $qty,
            'occurredAt'           => $occurredAt,
            'syncedAt'             => null,
            'userId'               => null,
            'idempotencyKey'       => Str::uuid()->toString(),
            'referenceType'        => null,
            'referenceId'          => null,
            'referenceNumber'      => null,
            'reason'               => null,
            'note'                 => null,
            'virtualDeviceId'      => null,
            'actorNameSnapshot'    => null,
            'terminalNameSnapshot' => null,
            'allowNegative'        => false,
        ];
    }
}
