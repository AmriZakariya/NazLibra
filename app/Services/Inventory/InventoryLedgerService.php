<?php

namespace App\Services\Inventory;

use App\Models\InventoryLayer;
use App\Models\InventoryLayerConsumption;
use App\Models\InventoryMovement;
use App\Enums\ItemStatus;
use App\Models\Item;
use App\Models\ItemLocationStock;
use App\Models\Location;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleItemConsumption;
use App\Models\Tenant;
use App\Exceptions\InsufficientStockException;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * InventoryLedgerService — the single authoritative engine for all inventory
 * valuation and stock movement logic.
 *
 * Design principles:
 *   - All inventory state is derived from inventory_layers and
 *     inventory_layer_consumptions. No other source is authoritative.
 *   - Every incoming movement creates one InventoryLayer.
 *   - Every outgoing movement consumes layers using the tenant's costing method (LIFO/FIFO/WAC).
 *   - ItemLocationStock and items.stock_quantity are read-cache only —
 *     always recomputed from layers by syncStockCache().
 *
 * Negative stock (the shop's real figure, never clamped at 0):
 *   - Layers never go below 0, so FIFO/LIFO/WAC and the stock value are
 *     untouched. What an allowed outgoing movement takes that no layer has is
 *     the item+location DEFICIT (item_location_stock.deficit_quantity):
 *         cache quantity = SUM(layers remaining) - deficit
 *   - Incoming stock settles the deficit FIRST: -0,02 then +1 box gives 0,98
 *     and the new layer holds 0,98 (the settled part never becomes shelf
 *     stock, nor stock value).
 *   - COGS is corrected with the real cost (« Correct with real cost »): for
 *     each settled part, the sale line / consumption that took it is trued up
 *     by settled x (incoming unit cost - the fallback unit cost booked then);
 *     sale_items.total_cost, sales.cogs and the movement's total_cost follow.
 *     Old movements' cogs (the layers' figure) are never rewritten.
 *   - Availability for selling is the layers' sum (>= 0) and is unchanged.
 *   - No frontend or mobile client may call these methods directly.
 *     Controllers call us; we call nobody above us.
 *   - All operations are atomic (wrapped in DB::transaction).
 *   - Idempotency is enforced via idempotency_key per tenant.
 *   - Backdated offline operations trigger a full ledger rebuild from the
 *     affected point in time forward.
 */
class InventoryLedgerService
{
    /** Per-request cache so each tenant is loaded at most once per HTTP cycle. */
    private array $costingMethodCache = [];

    // ── Public: incoming movement ───────────────────────────────────────────────

    /**
     * Record an incoming stock movement and create an inventory layer.
     *
     * Use for: purchases, adjustment-in, manual-add, sale-cancellation restocks,
     * customer returns (restock), transfer-in, initial stock, stocktake.
     *
     * @param  array{
     *   tenantId: int,
     *   itemId: int,
     *   variantId: int|null,
     *   locationId: int,
     *   type: string,
     *   quantity: float,
     *   unitCost: float,
     *   occurredAt: Carbon,
     *   syncedAt: Carbon|null,
     *   userId: int|null,
     *   idempotencyKey: string|null,
     *   referenceType: string|null,
     *   referenceId: int|null,
     *   referenceNumber: string|null,
     *   reason: string|null,
     *   note: string|null,
     *   virtualDeviceId: int|null,
     *   actorNameSnapshot: string|null,
     *   terminalNameSnapshot: string|null,
     * } $params
     *
     * @return array{movement: InventoryMovement, layer: InventoryLayer|null, already_existed: bool, cogs: float}
     */
    public function createIncomingMovement(array $params): array
    {
        return DB::transaction(function () use ($params): array {
            $tenantId   = $params['tenantId'];
            $itemId     = $params['itemId'];
            $variantId  = $params['variantId'] ?? null;
            $locationId = $params['locationId'];
            $occurredAt = $params['occurredAt'];
            $quantity   = (float) $params['quantity'];
            $unitCost   = (float) ($params['unitCost'] ?? 0);

            // ── Idempotency ───────────────────────────────────────────────────
            if (! empty($params['idempotencyKey'])) {
                $existing = InventoryMovement::where('tenant_id', $tenantId)
                    ->where('idempotency_key', $params['idempotencyKey'])
                    ->first();

                if ($existing) {
                    $layer = InventoryLayer::where('source_movement_id', $existing->id)->first();
                    return [
                        'movement'       => $existing,
                        'layer'          => $layer,
                        'already_existed' => true,
                        'cogs'           => 0.0,
                    ];
                }
            }

            // ── Determine if this is backdated ────────────────────────────────
            $isBackdated = $this->isBackdated($tenantId, $itemId, $locationId, $occurredAt);

            // ── Lock / create the stock cache row ─────────────────────────────
            $stock         = $this->lockOrCreateStock($tenantId, $itemId, $variantId, $locationId);
            $quantityBefore = (float) $stock->quantity;
            $quantityAfter  = $quantityBefore + $quantity;
            $settled        = 0.0;

            // ── Create the movement record ────────────────────────────────────
            $movement = InventoryMovement::create([
                'tenant_id'            => $tenantId,
                'item_id'              => $itemId,
                'variant_id'           => $variantId,
                'location_id'          => $locationId,
                'user_id'              => $params['userId'] ?? null,
                'type'                 => $params['type'],
                'quantity_before'      => $quantityBefore,
                'quantity_delta'       => $quantity,
                'quantity_after'       => $quantityAfter,
                'unit_cost'            => $unitCost,
                'total_cost'           => round($quantity * $unitCost, 4),
                'cogs'                 => null, // incoming = no COGS
                'occurred_at'          => $occurredAt,
                'synced_at'            => $params['syncedAt'] ?? null,
                'idempotency_key'      => $params['idempotencyKey'] ?? null,
                'reference_type'       => $params['referenceType'] ?? null,
                'reference_id'         => $params['referenceId'] ?? null,
                'reference_number'     => $params['referenceNumber'] ?? null,
                'reason'               => $params['reason'] ?? null,
                'note'                 => $params['note'] ?? null,
                'virtual_device_id'    => $params['virtualDeviceId'] ?? null,
                'actor_name_snapshot'  => $params['actorNameSnapshot'] ?? null,
                'terminal_name_snapshot' => $params['terminalNameSnapshot'] ?? null,
            ]);

            $layer = null;

            if ($isBackdated) {
                // Rebuild replays all movements — including this one — in order.
                $this->rebuildFrom($tenantId, $itemId, $variantId, $locationId, $occurredAt);
                $layer = InventoryLayer::where('source_movement_id', $movement->id)->first();
            } else {
                // Fast path: the part that pays a deficit never becomes shelf stock.
                $settled = $this->settleDeficit($stock, $movement, $quantity, $unitCost);
                $layer = $this->createLayer($movement, round($quantity - $settled, 4), $unitCost, $occurredAt);
                $this->syncStockCache($tenantId, $itemId, $variantId, $locationId);
            }

            return [
                'movement'       => $movement->fresh(),
                'layer'          => $layer,
                'already_existed' => false,
                'cogs'           => 0.0,
            ];
        });
    }

    // ── Public: outgoing movement ───────────────────────────────────────────────

    /**
     * Record an outgoing stock movement and consume inventory layers via LIFO.
     *
     * Use for: sales, adjustment-out, manual-deduct, purchase returns,
     * damage/loss/expiry, transfer-out.
     *
     * @param  array{
     *   tenantId: int,
     *   itemId: int,
     *   variantId: int|null,
     *   locationId: int,
     *   type: string,
     *   quantity: float,
     *   occurredAt: Carbon,
     *   syncedAt: Carbon|null,
     *   userId: int|null,
     *   idempotencyKey: string|null,
     *   referenceType: string|null,
     *   referenceId: int|null,
     *   referenceNumber: string|null,
     *   reason: string|null,
     *   note: string|null,
     *   virtualDeviceId: int|null,
     *   actorNameSnapshot: string|null,
     *   terminalNameSnapshot: string|null,
     *   allowNegative: bool,
     * } $params
     *
     * @return array{movement: InventoryMovement, consumptions: Collection, cogs: float, unitCost: float, already_existed: bool}
     * @throws InsufficientStockException
     */
    public function createOutgoingMovement(array $params): array
    {
        return DB::transaction(function () use ($params): array {
            $tenantId      = $params['tenantId'];
            $itemId        = $params['itemId'];
            $variantId     = $params['variantId'] ?? null;
            $locationId    = $params['locationId'];
            $occurredAt    = $params['occurredAt'];
            $quantity      = (float) $params['quantity'];
            $allowNegative = (bool) ($params['allowNegative'] ?? false);

            // ── Idempotency ───────────────────────────────────────────────────
            if (! empty($params['idempotencyKey'])) {
                $existing = InventoryMovement::where('tenant_id', $tenantId)
                    ->where('idempotency_key', $params['idempotencyKey'])
                    ->first();

                if ($existing) {
                    $consumptions = InventoryLayerConsumption::where('outgoing_movement_id', $existing->id)->get();
                    return [
                        'movement'        => $existing,
                        'consumptions'    => $consumptions,
                        'cogs'            => (float) ($existing->cogs ?? 0),
                        'unitCost'        => $quantity > 0 ? round((float) ($existing->cogs ?? 0) / $quantity, 4) : 0.0,
                        'already_existed' => true,
                    ];
                }
            }

            // ── Availability check (from layers — authoritative) ───────────────
            if (! $allowNegative) {
                $available = $this->availableQuantity($tenantId, $itemId, $variantId, $locationId);
                if ($available < $quantity) {
                    throw new InsufficientStockException(
                        itemId:    $itemId,
                        locationId: $locationId,
                        available: (int) $available,
                        requested: (int) $quantity,
                    );
                }
            }

            // ── Determine if this is backdated ────────────────────────────────
            $isBackdated = $this->isBackdated($tenantId, $itemId, $locationId, $occurredAt);

            // ── Lock stock cache row ──────────────────────────────────────────
            $stock          = $this->lockOrCreateStock($tenantId, $itemId, $variantId, $locationId);
            $quantityBefore = (float) $stock->quantity;
            $quantityAfter  = round($quantityBefore - $quantity, 4); // the real figure, negative included

            // ── Create the movement record first (COGS updated after consumption) ──
            $movement = InventoryMovement::create([
                'tenant_id'            => $tenantId,
                'item_id'              => $itemId,
                'variant_id'           => $variantId,
                'location_id'          => $locationId,
                'user_id'              => $params['userId'] ?? null,
                'type'                 => $params['type'],
                'quantity_before'      => $quantityBefore,
                'quantity_delta'       => -$quantity,
                'quantity_after'       => $quantityAfter,
                'unit_cost'            => null, // updated after LIFO consumption
                'total_cost'           => null, // updated after LIFO consumption
                'cogs'                 => null, // updated after LIFO consumption
                'deficit_unit_cost'    => (float) ($params['deficitUnitCost'] ?? 0),
                'occurred_at'          => $occurredAt,
                'synced_at'            => $params['syncedAt'] ?? null,
                'idempotency_key'      => $params['idempotencyKey'] ?? null,
                'reference_type'       => $params['referenceType'] ?? null,
                'reference_id'         => $params['referenceId'] ?? null,
                'reference_number'     => $params['referenceNumber'] ?? null,
                'reason'               => $params['reason'] ?? null,
                'note'                 => $params['note'] ?? null,
                'virtual_device_id'    => $params['virtualDeviceId'] ?? null,
                'actor_name_snapshot'  => $params['actorNameSnapshot'] ?? null,
                'terminal_name_snapshot' => $params['terminalNameSnapshot'] ?? null,
            ]);

            $consumptions = collect();
            $cogs         = 0.0;

            if ($isBackdated) {
                // Rebuild replays all movements in order, including this one.
                $this->rebuildFrom($tenantId, $itemId, $variantId, $locationId, $occurredAt);
                $consumptions = InventoryLayerConsumption::where('outgoing_movement_id', $movement->id)->get();
                $cogs         = (float) $consumptions->sum('total_cost');
            } else {
                // Fast path: consume layers via the tenant's configured method.
                [$consumptions, $cogs] = $this->consumeLayersByMethod(
                    $tenantId, $itemId, $variantId, $locationId, $quantity, $movement->id
                );
                // What no layer had is the deficit: the shelf shows it, negative.
                $shortfall = round($quantity - (float) $consumptions->sum('quantity_consumed'), 4);
                if ($shortfall > 0.00005) {
                    $movement->forceFill(['deficit_quantity' => $shortfall])->save();
                    $stock->forceFill(['deficit_quantity' => round((float) $stock->deficit_quantity + $shortfall, 4)])->save();
                }
                $this->syncStockCache($tenantId, $itemId, $variantId, $locationId);
            }

            // ── Update movement with computed COGS ────────────────────────────
            $avgUnitCost = $quantity > 0 ? round($cogs / $quantity, 4) : 0.0;
            $movement->update([
                'unit_cost'  => $avgUnitCost,
                'total_cost' => $cogs,
                'cogs'       => $cogs,
            ]);

            return [
                'movement'        => $movement->fresh(),
                'consumptions'    => $consumptions,
                'cogs'            => $cogs,
                'unitCost'        => $avgUnitCost,
                'already_existed' => false,
            ];
        });
    }

    // ── Public: stock queries (authoritative — always from layers) ──────────────

    /**
     * Available quantity for an item at a location.
     * Source of truth: SUM(remaining_quantity) from inventory_layers.
     */
    public function availableQuantity(int $tenantId, int $itemId, ?int $variantId, int $locationId): float
    {
        return (float) InventoryLayer::where('tenant_id', $tenantId)
            ->where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->when($variantId, fn ($q) => $q->where('variant_id', $variantId))
            ->where('remaining_quantity', '>', 0)
            ->sum('remaining_quantity');
    }

    /**
     * Repair legacy/cache drift before POS operations.
     *
     * Mobile sync displays ItemLocationStock.quantity. Sales consume
     * InventoryLayer.remaining_quantity. If old imports or previous code wrote
     * only the cache row, the cashier sees stock while the sale API sees zero.
     * This creates a correction layer for the positive shortfall so both views
     * use the same available quantity again.
     */
    public function reconcilePositiveStockCacheShortfall(
        int $tenantId,
        int $itemId,
        ?int $variantId,
        int $locationId,
        ?int $userId = null,
        ?int $virtualDeviceId = null,
        ?string $actorNameSnapshot = null,
        ?string $terminalNameSnapshot = null,
    ): void {
        DB::transaction(function () use (
            $tenantId,
            $itemId,
            $variantId,
            $locationId,
            $userId,
            $virtualDeviceId,
            $actorNameSnapshot,
            $terminalNameSnapshot,
        ): void {
            $stock = ItemLocationStock::where('tenant_id', $tenantId)
                ->where('item_id', $itemId)
                ->where('location_id', $locationId)
                ->when($variantId, fn ($q) => $q->where('variant_id', $variantId))
                ->lockForUpdate()
                ->first();

            $cacheQty = (float) ($stock?->quantity ?? 0);
            if ($cacheQty <= 0) {
                return;
            }

            // lockForUpdate so both reads are consistent inside the transaction
            // and a concurrent layer insert cannot slip between the two checks.
            $layerQty = (float) InventoryLayer::where('tenant_id', $tenantId)
                ->where('item_id', $itemId)
                ->where('location_id', $locationId)
                ->when($variantId, fn ($q) => $q->where('variant_id', $variantId))
                ->where('remaining_quantity', '>', 0)
                ->lockForUpdate()
                ->selectRaw('COALESCE(SUM(remaining_quantity), 0) as s')
                ->value('s') ?? 0.0;

            if ($layerQty + 0.0001 >= $cacheQty) {
                return;
            }

            $delta = round($cacheQty - $layerQty, 4);
            $unitCost = (float) ($stock?->average_cost ?? 0);
            $now = now();

            $movement = InventoryMovement::create([
                'tenant_id'              => $tenantId,
                'item_id'                => $itemId,
                'variant_id'             => $variantId,
                'location_id'            => $locationId,
                'user_id'                => $userId,
                'type'                   => InventoryMovementType::CORRECTION,
                'quantity_before'        => $layerQty,
                'quantity_delta'         => $delta,
                'quantity_after'         => $cacheQty,
                'unit_cost'              => $unitCost,
                'total_cost'             => round($delta * $unitCost, 4),
                'cogs'                   => null,
                'occurred_at'            => $now,
                'synced_at'              => null,
                'idempotency_key'        => 'stock-cache-reconcile-'.$tenantId.'-'.$itemId.'-'.($variantId ?? 'base').'-'.$locationId.'-'.$now->format('YmdHisv'),
                'reference_type'         => ItemLocationStock::class,
                'reference_id'           => $stock?->id,
                'reference_number'       => null,
                'reason'                 => 'Synchronisation cache stock / couches LIFO',
                'note'                   => 'Correction automatique avant vente POS',
                'virtual_device_id'      => $virtualDeviceId,
                'actor_name_snapshot'    => $actorNameSnapshot,
                'terminal_name_snapshot' => $terminalNameSnapshot,
            ]);

            $this->createLayer($movement, $delta, $unitCost, $now);
            $this->syncStockCache($tenantId, $itemId, $variantId, $locationId);
        });
    }

    /**
     * Current inventory value at a location.
     * Value = SUM(remaining_quantity * unit_cost).
     * NEVER uses product.purchase_price or any static price field.
     */
    public function inventoryValue(int $tenantId, int $itemId, int $locationId): float
    {
        return (float) InventoryLayer::where('tenant_id', $tenantId)
            ->where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->where('remaining_quantity', '>', 0)
            ->selectRaw('SUM(remaining_quantity * unit_cost) as val')
            ->value('val') ?? 0.0;
    }

    /**
     * Current average cost at a location.
     * average_cost = inventory_value / current_quantity.
     * Returns 0 when no stock exists.
     */
    public function averageCost(int $tenantId, int $itemId, int $locationId): float
    {
        $qty   = $this->availableQuantity($tenantId, $itemId, null, $locationId);
        $value = $this->inventoryValue($tenantId, $itemId, $locationId);

        return $qty > 0 ? round($value / $qty, 4) : 0.0;
    }

    /**
     * COGS for a specific sale: sum of all layer consumption costs for the
     * SALE-type movements attached to this sale.
     */
    public function saleCogs(int $saleId): float
    {
        $movementIds = InventoryMovement::where('reference_type', 'App\Models\Sale')
            ->where('reference_id', $saleId)
            ->where('type', InventoryMovementType::SALE)
            ->pluck('id');

        return (float) InventoryLayerConsumption::whereIn('outgoing_movement_id', $movementIds)
            ->sum('total_cost');
    }

    /**
     * Inventory summary for a specific item at a location.
     * Returns quantity, value, average_cost — all from layers.
     */
    public function itemSummary(int $tenantId, int $itemId, int $locationId): array
    {
        $quantity = $this->availableQuantity($tenantId, $itemId, null, $locationId);
        $value    = $this->inventoryValue($tenantId, $itemId, $locationId);

        return [
            'quantity'     => $quantity,
            'value'        => $value,
            'average_cost' => $quantity > 0 ? round($value / $quantity, 4) : 0.0,
        ];
    }

    // ── Public: rebuild ─────────────────────────────────────────────────────────

    /**
     * Rebuild inventory layers and consumptions from a given point in time.
     *
     * Called automatically when a backdated offline operation is synced.
     * The rebuild:
     *   1. Deletes consumptions for all outgoing movements at/after fromOccurredAt.
     *   2. Restores remaining_quantity on layers whose consumptions were deleted.
     *   3. Deletes layers created by incoming movements at/after fromOccurredAt.
     *   4. Replays all movements from fromOccurredAt forward in chronological order.
     *   5. Re-syncs the stock cache.
     *
     * Business documents (sales, purchases, returns) are NOT touched.
     * Only layers and consumptions are rebuilt.
     *
     * Must be called within a DB::transaction (or starts its own).
     */
    public function rebuildFrom(
        int $tenantId,
        int $itemId,
        ?int $variantId,
        int $locationId,
        Carbon $fromOccurredAt,
    ): void {
        DB::transaction(function () use ($tenantId, $itemId, $variantId, $locationId, $fromOccurredAt): void {

            // ── Step 1: Find all outgoing movements from rebuild point forward ──
            // Use quantity_delta sign, not type, so CORRECTION works for both directions.
            $outgoingIds = InventoryMovement::where('tenant_id', $tenantId)
                ->where('item_id', $itemId)
                ->where('location_id', $locationId)
                ->where('occurred_at', '>=', $fromOccurredAt)
                ->where('quantity_delta', '<', 0)
                ->pluck('id');

            // ── Step 2: Collect affected layer IDs BEFORE deleting consumptions ──
            // (layers created BEFORE fromOccurredAt that had post-date consumptions)
            $affectedLayerIds = InventoryLayerConsumption::whereIn('outgoing_movement_id', $outgoingIds)
                ->pluck('inventory_layer_id')
                ->unique();

            // ── Step 3: Delete consumptions for those movements ───────────────
            InventoryLayerConsumption::whereIn('outgoing_movement_id', $outgoingIds)->delete();

            // ── Step 4: Restore remaining_quantity on pre-date layers that had
            //   consumptions deleted in step 3 ──────────────────────────────────
            foreach (InventoryLayer::whereIn('id', $affectedLayerIds)->get() as $layer) {
                $alreadyConsumed = InventoryLayerConsumption::where('inventory_layer_id', $layer->id)
                    ->sum('quantity_consumed');
                $layer->remaining_quantity = max(0, (float) $layer->original_quantity - (float) $alreadyConsumed);
                $layer->exhausted_at = $layer->remaining_quantity <= 0 ? ($layer->exhausted_at ?? now()) : null;
                $layer->save();
            }

            // ── Step 5: Delete layers created by incoming movements at/after rebuild point ──
            $incomingIds = InventoryMovement::where('tenant_id', $tenantId)
                ->where('item_id', $itemId)
                ->where('location_id', $locationId)
                ->where('occurred_at', '>=', $fromOccurredAt)
                ->where('quantity_delta', '>', 0)
                ->pluck('id');

            InventoryLayer::whereIn('source_movement_id', $incomingIds)->delete();

            // ── Step 6: Replay all movements from rebuild point in chronological order ──
            // Use quantity_delta sign: positive = creates layer, negative = consumes LIFO.
            // This handles CORRECTION and any other bidirectional type correctly.
            $movements = InventoryMovement::where('tenant_id', $tenantId)
                ->where('item_id', $itemId)
                ->where('location_id', $locationId)
                ->where('occurred_at', '>=', $fromOccurredAt)
                ->orderBy('occurred_at')
                ->orderBy('synced_at')   // online ops (null) before synced ops at same time
                ->orderBy('id')          // deterministic tiebreaker
                ->get();

            // The deficit standing just before the replay: the figure the last
            // earlier movement left (negative = layers empty, deficit = -figure).
            $previous = InventoryMovement::where('tenant_id', $tenantId)
                ->where('item_id', $itemId)
                ->where('location_id', $locationId)
                ->where('occurred_at', '<', $fromOccurredAt)
                ->orderByDesc('occurred_at')->orderByDesc('id')
                ->first(['quantity_after']);
            $deficit = $previous ? max(0.0, -(float) $previous->quantity_after) : 0.0;

            foreach ($movements as $movement) {
                $delta = (float) $movement->quantity_delta;

                if ($delta > 0) {
                    // Settles the deficit first, as live. The cost true-ups of
                    // the sales it paid for were booked when they happened.
                    $settle = round(min($deficit, $delta), 4);
                    $deficit = round($deficit - $settle, 4);
                    $this->createLayer($movement, round($delta - $settle, 4), (float) ($movement->unit_cost ?? 0), $movement->occurred_at);
                } elseif ($delta < 0) {
                    $qty = abs($delta);
                    [$consumptions, $cogs] = $this->consumeLayersByMethod(
                        $tenantId, $itemId, $variantId, $locationId, $qty, $movement->id
                    );
                    $short = round($qty - (float) $consumptions->sum('quantity_consumed'), 4);
                    if ($short > 0.00005) {
                        $deficit = round($deficit + $short, 4);
                        if ((float) $movement->deficit_settled_quantity <= 0) {
                            $movement->forceFill(['deficit_quantity' => $short])->save();
                        }
                    }
                    $avgUnit = $qty > 0 ? round($cogs / $qty, 4) : 0.0;
                    $movement->update([
                        'unit_cost'  => $avgUnit,
                        'total_cost' => $cogs,
                        'cogs'       => $cogs,
                    ]);
                }
            }

            // ── Step 7: Rebuild stock cache from layers ────────────────────────
            ItemLocationStock::where('tenant_id', $tenantId)
                ->where('item_id', $itemId)
                ->where('location_id', $locationId)
                ->when($variantId, fn ($q) => $q->where('variant_id', $variantId))
                ->update(['deficit_quantity' => $deficit]);
            $this->syncStockCache($tenantId, $itemId, $variantId, $locationId);
        });
    }

    // ── Public: cache sync ──────────────────────────────────────────────────────

    /**
     * Recompute and update the cached stock fields from the layer ledger.
     *
     * ItemLocationStock.quantity       = SUM(remaining_quantity)
     * ItemLocationStock.average_cost   = inventory_value / quantity
     * items.stock_quantity             = SUM across all locations
     *
     * These are read-caches only. The authoritative values always come from layers.
     */
    public function syncStockCache(
        int $tenantId,
        int $itemId,
        ?int $variantId,
        int $locationId,
    ): void {
        // Single pass: fetch both aggregates in one query instead of two.
        $agg = InventoryLayer::where('tenant_id', $tenantId)
            ->where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->when($variantId, fn ($q) => $q->where('variant_id', $variantId))
            ->selectRaw('
                COALESCE(SUM(remaining_quantity), 0) as qty,
                COALESCE(SUM(CASE WHEN remaining_quantity > 0 THEN remaining_quantity * unit_cost ELSE 0 END), 0) as val
            ')
            ->first();

        $layerQty    = (float) ($agg->qty ?? 0);
        $value       = (float) ($agg->val ?? 0);
        $averageCost = $layerQty > 0 ? round($value / $layerQty, 4) : 0.0;

        // The real figure: what the layers hold minus what was taken beyond them.
        $deficit = (float) ItemLocationStock::where('tenant_id', $tenantId)
            ->where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->when($variantId, fn ($q) => $q->where('variant_id', $variantId))
            ->sum('deficit_quantity');
        $quantity = round($layerQty - $deficit, 4);

        // Update or create the ItemLocationStock cache row.
        ItemLocationStock::where('tenant_id', $tenantId)
            ->where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->when($variantId, fn ($q) => $q->where('variant_id', $variantId))
            ->update([
                'quantity'     => $quantity,
                'average_cost' => $averageCost,
                'updated_at'   => now(),
            ]);

        // Sync denormalized total on the item (sum across all locations).
        $totalQty = (float) ItemLocationStock::where('tenant_id', $tenantId)
            ->where('item_id', $itemId)
            ->sum('quantity');

        $item = Item::where('tenant_id', $tenantId)->where('id', $itemId)->first();

        if ($item) {
            $newStatus = ItemStatus::fromTypeAndStock($item->type, $totalQty);
            $updates = ['stock_quantity' => $totalQty, 'updated_at' => now()];

            // Only promote/demote between active and out_of_stock; never touch
            // archived or inactive items.
            if (in_array($item->status, [ItemStatus::Active->value, ItemStatus::OutOfStock->value], true)) {
                $updates['status'] = $newStatus->value;
            }

            $item->update($updates);
        }
    }

    // ── Private helpers ─────────────────────────────────────────────────────────

    /**
     * Create an inventory layer for an incoming movement.
     * Must be called inside a DB::transaction.
     */
    private function createLayer(
        InventoryMovement $movement,
        float $quantity,
        float $unitCost,
        Carbon $occurredAt,
    ): InventoryLayer {
        return InventoryLayer::create([
            'tenant_id'          => $movement->tenant_id,
            'item_id'            => $movement->item_id,
            'variant_id'         => $movement->variant_id,
            'location_id'        => $movement->location_id,
            'source_movement_id' => $movement->id,
            'original_quantity'  => $quantity,
            'remaining_quantity' => $quantity,
            'unit_cost'          => $unitCost,
            'occurred_at'        => $occurredAt,
            // A layer whose whole quantity paid a deficit is born empty.
            'exhausted_at'       => $quantity <= 0 ? now() : null,
        ]);
    }

    /**
     * An incoming movement pays the deficit first. Returns the part it settled.
     *
     * Each settled part is trued up on the outgoing movement that took it
     * (oldest first): the sale was costed at the fallback price (or nothing),
     * the real cost is the incoming unit cost. Must run in a transaction.
     */
    private function settleDeficit(ItemLocationStock $stock, InventoryMovement $incoming, float $quantity, float $unitCost): float
    {
        $deficit = (float) $stock->deficit_quantity;
        if ($deficit <= 0.00005 || $quantity <= 0) {
            return 0.0;
        }

        $settle = round(min($deficit, $quantity), 4);
        $stock->forceFill(['deficit_quantity' => round($deficit - $settle, 4)])->save();
        $incoming->forceFill(['settled_quantity' => $settle])->save();

        $left = $settle;
        $open = InventoryMovement::where('tenant_id', $incoming->tenant_id)
            ->where('item_id', $incoming->item_id)
            ->where('location_id', $incoming->location_id)
            ->when($incoming->variant_id, fn ($q) => $q->where('variant_id', $incoming->variant_id))
            ->whereRaw('deficit_quantity > deficit_settled_quantity')
            ->orderBy('occurred_at')->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($open as $outgoing) {
            if ($left <= 0.00005) {
                break;
            }
            $take = round(min($left, (float) $outgoing->deficit_quantity - (float) $outgoing->deficit_settled_quantity), 4);
            if ($take <= 0) {
                continue;
            }
            $left = round($left - $take, 4);
            $trueUp = round($take * ($unitCost - (float) $outgoing->deficit_unit_cost), 4);

            $qty = abs((float) $outgoing->quantity_delta);
            $newTotal = round((float) $outgoing->total_cost + $trueUp, 4);
            $outgoing->forceFill([
                'deficit_settled_quantity' => round((float) $outgoing->deficit_settled_quantity + $take, 4),
                'total_cost' => $newTotal,
                'unit_cost' => $qty > 0 ? round($newTotal / $qty, 4) : $outgoing->unit_cost,
            ])->save();

            if (abs($trueUp) > 0.00005) {
                $this->trueUpSale($outgoing, $trueUp);
            }
        }

        return $settle;
    }

    /** Carries a COGS true-up to the business documents that took the stock. */
    private function trueUpSale(InventoryMovement $outgoing, float $trueUp): void
    {
        $line = null;
        if ($outgoing->reference_type === SaleItem::class) {
            $line = SaleItem::find($outgoing->reference_id);
            $consumption = SaleItemConsumption::where('movement_id', $outgoing->id)->first();
            if ($consumption) {
                $qty = (float) $consumption->quantity_stock;
                $total = round((float) $consumption->total_cost + $trueUp, 4);
                $consumption->forceFill([
                    'total_cost' => $total,
                    'unit_cost' => $qty > 0 ? round($total / $qty, 4) : $consumption->unit_cost,
                ])->save();
            }
        } elseif ($outgoing->reference_type === Sale::class) {
            if (preg_match('/-line-(\d+)$/', (string) $outgoing->idempotency_key, $m)) {
                $line = SaleItem::where('sale_id', $outgoing->reference_id)->whereKey((int) $m[1])->first();
            }
            $line ??= SaleItem::where('sale_id', $outgoing->reference_id)->where('item_id', $outgoing->item_id)->orderBy('id')->first();
        }
        if (! $line) {
            return;
        }

        $lineQty = max(1, (float) $line->quantity);
        $line->forceFill([
            'total_cost' => round((float) $line->total_cost + $trueUp, 4),
            'unit_cost' => round((float) $line->unit_cost + $trueUp / $lineQty, 4),
        ])->save();

        $sale = Sale::find($line->sale_id);
        if ($sale) {
            $sale->cogs = round((float) $sale->cogs + $trueUp, 4);
            $meta = $sale->metadata;
            if (is_array($meta) && isset($meta['cogs']['total'])) {
                $meta['cogs']['total'] = round((float) $meta['cogs']['total'] + $trueUp, 4);
                $sale->metadata = $meta;
            }
            $sale->save();
        }
    }

    /**
     * Consume inventory layers in LIFO order (newest first).
     *
     * Must be called inside a DB::transaction — uses lockForUpdate().
     *
     * Returns [$consumptions (Collection), $totalCogs (float)].
     */
    private function consumeLayersLifo(
        int $tenantId,
        int $itemId,
        ?int $variantId,
        int $locationId,
        float $quantity,
        int $outgoingMovementId,
    ): array {
        $remainingToConsume = $quantity;
        $consumptions       = collect();
        $totalCogs          = 0.0;

        // Lock all non-exhausted layers for this item/location.
        // LIFO = ORDER BY occurred_at DESC, id DESC (newest first).
        $layers = InventoryLayer::where('tenant_id', $tenantId)
            ->where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->when($variantId, fn ($q) => $q->where('variant_id', $variantId))
            ->where('remaining_quantity', '>', 0)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->get();

        foreach ($layers as $layer) {
            if ($remainingToConsume <= 0) {
                break;
            }

            $consumeQty  = min($remainingToConsume, (float) $layer->remaining_quantity);
            $layerCost   = round($consumeQty * (float) $layer->unit_cost, 4);

            $consumption = InventoryLayerConsumption::create([
                'outgoing_movement_id' => $outgoingMovementId,
                'inventory_layer_id'   => $layer->id,
                'quantity_consumed'    => $consumeQty,
                'unit_cost'            => $layer->unit_cost,
                'total_cost'           => $layerCost,
            ]);

            $consumptions->push($consumption);
            $totalCogs += $layerCost;

            $newRemaining = (float) $layer->remaining_quantity - $consumeQty;
            $layer->remaining_quantity = max(0, $newRemaining);
            $layer->exhausted_at = $newRemaining <= 0 ? now() : null;
            $layer->save();

            $remainingToConsume -= $consumeQty;
        }

        return [$consumptions, round($totalCogs, 4)];
    }

    /**
     * Consume layers in FIFO order (oldest first — suited for retail/food).
     */
    private function consumeLayersFifo(
        int $tenantId,
        int $itemId,
        ?int $variantId,
        int $locationId,
        float $quantity,
        int $outgoingMovementId,
    ): array {
        $remainingToConsume = $quantity;
        $consumptions       = collect();
        $totalCogs          = 0.0;

        $layers = InventoryLayer::where('tenant_id', $tenantId)
            ->where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->when($variantId, fn ($q) => $q->where('variant_id', $variantId))
            ->where('remaining_quantity', '>', 0)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($layers as $layer) {
            if ($remainingToConsume <= 0) {
                break;
            }

            $consumeQty = min($remainingToConsume, (float) $layer->remaining_quantity);
            $layerCost  = round($consumeQty * (float) $layer->unit_cost, 4);

            $consumptions->push(InventoryLayerConsumption::create([
                'outgoing_movement_id' => $outgoingMovementId,
                'inventory_layer_id'   => $layer->id,
                'quantity_consumed'    => $consumeQty,
                'unit_cost'            => $layer->unit_cost,
                'total_cost'           => $layerCost,
            ]));

            $totalCogs += $layerCost;
            $newRemaining = (float) $layer->remaining_quantity - $consumeQty;
            $layer->remaining_quantity = max(0, $newRemaining);
            $layer->exhausted_at = $newRemaining <= 0 ? now() : null;
            $layer->save();

            $remainingToConsume -= $consumeQty;
        }

        return [$consumptions, round($totalCogs, 4)];
    }

    /**
     * Consume layers using Weighted Average Cost.
     *
     * Average cost is computed live from all remaining layers at the moment of the
     * sale (not from the cached average_cost field), so backdated ops don't drift.
     * Layers are depleted in FIFO order (oldest first) but every consumption record
     * uses the average unit cost, not the per-layer cost.
     */
    private function consumeLayersWac(
        int $tenantId,
        int $itemId,
        ?int $variantId,
        int $locationId,
        float $quantity,
        int $outgoingMovementId,
    ): array {
        $remainingToConsume = $quantity;
        $consumptions       = collect();

        $layers = InventoryLayer::where('tenant_id', $tenantId)
            ->where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->when($variantId, fn ($q) => $q->where('variant_id', $variantId))
            ->where('remaining_quantity', '>', 0)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        // Compute weighted average from all available layers.
        $totalRemaining = $layers->sum(fn ($l) => (float) $l->remaining_quantity);
        $totalValue     = $layers->sum(fn ($l) => (float) $l->remaining_quantity * (float) $l->unit_cost);
        $avgUnitCost    = $totalRemaining > 0 ? round($totalValue / $totalRemaining, 4) : 0.0;
        $totalCogs      = round(min($quantity, $totalRemaining) * $avgUnitCost, 4);

        foreach ($layers as $layer) {
            if ($remainingToConsume <= 0) {
                break;
            }

            $consumeQty = min($remainingToConsume, (float) $layer->remaining_quantity);

            $consumptions->push(InventoryLayerConsumption::create([
                'outgoing_movement_id' => $outgoingMovementId,
                'inventory_layer_id'   => $layer->id,
                'quantity_consumed'    => $consumeQty,
                'unit_cost'            => $avgUnitCost,
                'total_cost'           => round($consumeQty * $avgUnitCost, 4),
            ]));

            $newRemaining = (float) $layer->remaining_quantity - $consumeQty;
            $layer->remaining_quantity = max(0, $newRemaining);
            $layer->exhausted_at = $newRemaining <= 0 ? now() : null;
            $layer->save();

            $remainingToConsume -= $consumeQty;
        }

        return [$consumptions, $totalCogs];
    }

    /**
     * Dispatch layer consumption to the method configured for this tenant.
     */
    private function consumeLayersByMethod(
        int $tenantId,
        int $itemId,
        ?int $variantId,
        int $locationId,
        float $quantity,
        int $outgoingMovementId,
    ): array {
        return match ($this->getTenantCostingMethod($tenantId)) {
            'fifo' => $this->consumeLayersFifo($tenantId, $itemId, $variantId, $locationId, $quantity, $outgoingMovementId),
            'wac'  => $this->consumeLayersWac($tenantId, $itemId, $variantId, $locationId, $quantity, $outgoingMovementId),
            default => $this->consumeLayersLifo($tenantId, $itemId, $variantId, $locationId, $quantity, $outgoingMovementId),
        };
    }

    /** Resolve and cache the tenant's configured costing method. */
    private function getTenantCostingMethod(int $tenantId): string
    {
        if (! isset($this->costingMethodCache[$tenantId])) {
            $raw = Tenant::where('id', $tenantId)->value('settings');
            $settings = is_array($raw) ? $raw : (json_decode($raw ?? '{}', true) ?? []);
            $this->costingMethodCache[$tenantId] = $settings['inventory']['costing_method'] ?? 'lifo';
        }

        return $this->costingMethodCache[$tenantId];
    }

    /**
     * Check if an operation is backdated relative to the latest known movement
     * for this item/location. A backdated operation requires a full ledger rebuild.
     */
    private function isBackdated(int $tenantId, int $itemId, int $locationId, Carbon $occurredAt): bool
    {
        $latestAt = InventoryMovement::where('tenant_id', $tenantId)
            ->where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->max('occurred_at');

        if ($latestAt === null) {
            return false;
        }

        return $occurredAt->lt(Carbon::parse($latestAt));
    }

    /**
     * Lock the ItemLocationStock row (creating it if it doesn't exist).
     * Must be called inside a DB::transaction.
     */
    public function lockOrCreateStock(
        int $tenantId,
        int $itemId,
        ?int $variantId,
        int $locationId,
    ): ItemLocationStock {
        $stock = ItemLocationStock::withTrashed()
            ->where('tenant_id', $tenantId)
            ->where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->when($variantId !== null, fn ($q) => $q->where('variant_id', $variantId))
            ->lockForUpdate()
            ->first();

        if ($stock?->trashed()) {
            $stock->restore();
        }

        if ($stock) {
            return $stock;
        }

        try {
            return ItemLocationStock::create([
                'tenant_id'                      => $tenantId,
                'item_id'                        => $itemId,
                'variant_id'                     => $variantId,
                'location_id'                    => $locationId,
                'quantity'                       => 0,
                'reserved_quantity'              => 0,
                'incoming_quantity'              => 0,
                'damaged_quantity'               => 0,
                'returned_quantity'              => 0,
                'transferred_quantity'           => 0,
                'awaiting_confirmation_quantity' => 0,
                'min_stock'                      => 0,
                'reorder_point'                  => 0,
                'average_cost'                   => 0,
                'last_purchase_cost'             => 0,
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            return ItemLocationStock::withTrashed()
                ->where('tenant_id', $tenantId)
                ->where('item_id', $itemId)
                ->where('location_id', $locationId)
                ->when($variantId !== null, fn ($q) => $q->where('variant_id', $variantId))
                ->lockForUpdate()
                ->firstOrFail();
        }
    }

    /**
     * Resolve the default location ID for a tenant.
     */
    public function defaultLocationId(int $tenantId): int
    {
        $location = Location::where('tenant_id', $tenantId)
            ->where('is_default', true)
            ->where('is_active', true)
            ->first();

        if ($location) {
            return $location->id;
        }

        return Location::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('id')
            ->firstOrFail()
            ->id;
    }
}
