<?php

namespace App\Services\Inventory;

use App\Models\InventoryMovement;
use App\Models\ItemLocationStock;
use App\Models\Location;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    /**
     * Perform a single inventory movement.
     *
     * The operation is atomic: the stock row is locked, updated, and the movement
     * is recorded. Idempotency is enforced via idempotency_key.
     */
    public function move(MovementDTO $dto): InventoryMovement
    {
        return DB::transaction(function () use ($dto) {
            if ($dto->idempotencyKey !== null) {
                $existing = InventoryMovement::query()
                    ->where('idempotency_key', $dto->idempotencyKey)
                    ->where('tenant_id', $dto->tenantId)
                    ->first();

                if ($existing) {
                    return $existing;
                }
            }

            $stock = $this->lockStock(
                $dto->tenantId,
                $dto->itemId,
                $dto->variantId,
                $dto->locationId
            );

            $quantityBefore = (int) $stock->quantity;
            $delta = $this->applyDelta($dto->type, $dto->quantityChanged);
            $quantityAfter = $quantityBefore + $delta;

            if ($quantityAfter < 0 && ! $dto->allowNegative) {
                throw new InsufficientStockException(
                    itemId: $dto->itemId,
                    locationId: $dto->locationId,
                    available: $quantityBefore,
                    requested: abs($delta),
                );
            }

            $stock->quantity = $quantityAfter;
            $stock->updated_at = now();
            $stock->save();

            $movement = InventoryMovement::query()->create([
                'tenant_id' => $dto->tenantId,
                'item_id' => $dto->itemId,
                'variant_id' => $dto->variantId,
                'location_id' => $dto->locationId,
                'user_id' => $dto->userId ?? auth()->id(),
                'type' => $dto->type,
                'quantity_before' => $quantityBefore,
                'quantity_delta' => $delta,
                'quantity_after' => $quantityAfter,
                'unit_cost' => $dto->unitCost,
                'total_cost' => $dto->totalCost(),
                'reference_type' => $dto->referenceType,
                'reference_id' => $dto->referenceId,
                'reference_number' => $dto->referenceNumber,
                'note' => $dto->note,
                'reason' => $dto->reason,
                'idempotency_key' => $dto->idempotencyKey,
                'virtual_device_id' => $dto->virtualDeviceId,
                'real_device_platform' => $dto->realDevicePlatform,
                'real_device_browser' => $dto->realDeviceBrowser,
                'real_device_ip' => $dto->realDeviceIp,
                'real_device_user_agent' => $dto->realDeviceUserAgent,
            ]);

            return $movement;
        });
    }

    /**
     * Get the available (quantity - reserved) stock for an item/variant at a location.
     */
    public function available(int $tenantId, int $itemId, ?int $variantId = null, ?int $locationId = null): int
    {
        $query = ItemLocationStock::query()
            ->where('tenant_id', $tenantId)
            ->where('item_id', $itemId);

        if ($variantId !== null) {
            $query->where('variant_id', $variantId);
        }

        if ($locationId !== null) {
            $query->where('location_id', $locationId);
        }

        return (int) $query
            ->selectRaw('SUM(quantity - reserved_quantity) as available')
            ->value('available') ?? 0;
    }

    /**
     * Get the total physical quantity for an item/variant at a location.
     */
    public function quantity(int $tenantId, int $itemId, ?int $variantId = null, ?int $locationId = null): int
    {
        $query = ItemLocationStock::query()
            ->where('tenant_id', $tenantId)
            ->where('item_id', $itemId);

        if ($variantId !== null) {
            $query->where('variant_id', $variantId);
        }

        if ($locationId !== null) {
            $query->where('location_id', $locationId);
        }

        return (int) $query->sum('quantity') ?? 0;
    }

    /**
     * Reserve stock. Creates a reservation movement that reduces available stock
     * but keeps physical quantity unchanged.
     */
    public function reserve(
        int $tenantId,
        int $itemId,
        ?int $variantId,
        int $locationId,
        int $quantity,
        string $reason,
        ?string $idempotencyKey = null,
        ?int $userId = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
    ): InventoryMovement {
        return DB::transaction(function () use (
            $tenantId, $itemId, $variantId, $locationId, $quantity, $reason,
            $idempotencyKey, $userId, $referenceType, $referenceId
        ) {
            $stock = $this->lockStock($tenantId, $itemId, $variantId, $locationId);

            if ($stock->availableQuantity() < $quantity) {
                throw new InsufficientStockException(
                    itemId: $itemId,
                    locationId: $locationId,
                    available: $stock->availableQuantity(),
                    requested: $quantity,
                );
            }

            $stock->reserved_quantity += $quantity;
            $stock->updated_at = now();
            $stock->save();

            $movement = InventoryMovement::query()->create([
                'tenant_id' => $tenantId,
                'item_id' => $itemId,
                'variant_id' => $variantId,
                'location_id' => $locationId,
                'user_id' => $userId ?? auth()->id(),
                'type' => InventoryMovementType::RESERVATION,
                'quantity_before' => $stock->quantity,
                'quantity_delta' => -$quantity,
                'quantity_after' => $stock->quantity,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'reason' => $reason,
                'idempotency_key' => $idempotencyKey ?? $this->generateIdempotencyKey(),
            ]);

            return $movement;
        });
    }

    /**
     * Release a previous reservation.
     */
    public function releaseReservation(
        int $tenantId,
        int $itemId,
        ?int $variantId,
        int $locationId,
        int $quantity,
        string $reason,
        ?string $idempotencyKey = null,
        ?int $userId = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
    ): InventoryMovement {
        return DB::transaction(function () use (
            $tenantId, $itemId, $variantId, $locationId, $quantity, $reason,
            $idempotencyKey, $userId, $referenceType, $referenceId
        ) {
            $stock = $this->lockStock($tenantId, $itemId, $variantId, $locationId);

            $release = min($quantity, $stock->reserved_quantity);
            $stock->reserved_quantity -= $release;
            $stock->updated_at = now();
            $stock->save();

            $movement = InventoryMovement::query()->create([
                'tenant_id' => $tenantId,
                'item_id' => $itemId,
                'variant_id' => $variantId,
                'location_id' => $locationId,
                'user_id' => $userId ?? auth()->id(),
                'type' => InventoryMovementType::RESERVATION_RELEASE,
                'quantity_before' => $stock->quantity,
                'quantity_delta' => $release,
                'quantity_after' => $stock->quantity,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'reason' => $reason,
                'idempotency_key' => $idempotencyKey ?? $this->generateIdempotencyKey(),
            ]);

            return $movement;
        });
    }

    /**
     * Find or create a locked ItemLocationStock row.
     */
    public function lockStock(int $tenantId, int $itemId, ?int $variantId, int $locationId): ItemLocationStock
    {
        $stock = ItemLocationStock::query()
            ->where('tenant_id', $tenantId)
            ->where('item_id', $itemId)
            ->where('variant_id', $variantId)
            ->where('location_id', $locationId)
            ->lockForUpdate()
            ->first();

        if ($stock) {
            return $stock;
        }

        // No row exists yet; create one. Because we are inside a transaction,
        // another concurrent caller could attempt the same. The unique index
        // will prevent duplicates and we retry once.
        try {
            return ItemLocationStock::query()->create([
                'tenant_id' => $tenantId,
                'item_id' => $itemId,
                'variant_id' => $variantId,
                'location_id' => $locationId,
                'quantity' => 0,
                'reserved_quantity' => 0,
                'incoming_quantity' => 0,
                'damaged_quantity' => 0,
                'returned_quantity' => 0,
                'transferred_quantity' => 0,
                'awaiting_confirmation_quantity' => 0,
                'min_stock' => 0,
                'reorder_point' => 0,
                'average_cost' => 0,
                'last_purchase_cost' => 0,
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            return ItemLocationStock::query()
                ->where('tenant_id', $tenantId)
                ->where('item_id', $itemId)
                ->where('variant_id', $variantId)
                ->where('location_id', $locationId)
                ->lockForUpdate()
                ->firstOrFail();
        }
    }

    /**
     * Resolve a location from a free-text warehouse/location name.
     */
    public function locationIdFromName(int $tenantId, ?string $name): int
    {
        if (! empty($name)) {
            $location = Location::query()
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->where(function ($query) use ($name) {
                    $query->where('name', $name)
                        ->orWhere('name', 'like', "%{$name}%");
                })
                ->first();

            if ($location) {
                return $location->id;
            }
        }

        return $this->defaultLocationId($tenantId);
    }

    /**
     * Resolve the default location for a tenant.
     */
    public function defaultLocationId(int $tenantId): int
    {
        $location = Location::query()
            ->where('tenant_id', $tenantId)
            ->where('is_default', true)
            ->where('is_active', true)
            ->first();

        if ($location) {
            return $location->id;
        }

        $location = Location::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('id')
            ->firstOrFail();

        return $location->id;
    }

    /**
     * Generate a UUID v4 idempotency key.
     */
    public function generateIdempotencyKey(): string
    {
        return (string) \Illuminate\Support\Str::uuid();
    }

    private function applyDelta(string $type, int $quantityChanged): int
    {
        // Signed corrections; preserve the caller's direction.
        if (in_array($type, [InventoryMovementType::ADJUSTMENT, InventoryMovementType::CORRECTION, InventoryMovementType::ITEM_UPDATE, InventoryMovementType::STOCKTAKE], true)) {
            return $quantityChanged;
        }

        if (InventoryMovementType::decreasesStock($type)) {
            return -abs($quantityChanged);
        }

        if (InventoryMovementType::increasesStock($type)) {
            return abs($quantityChanged);
        }

        // Types such as sale_cancel may be neutral depending on business rules.
        return $quantityChanged;
    }
}
