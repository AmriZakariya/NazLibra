<?php

namespace Tests\Unit;

use App\Models\Item;
use App\Models\ItemLocationStock;
use App\Models\Location;
use App\Services\Inventory\InventoryMovementType;
use App\Services\Inventory\InventoryService;
use App\Services\Inventory\MovementDTO;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryServiceTest extends TestCase
{
    use RefreshDatabase;

    private InventoryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->service = app(InventoryService::class);
    }

    public function test_move_increases_stock(): void
    {
        $item = Item::where('type', '!=', 'service')->firstOrFail();
        $location = Location::where('tenant_id', $item->tenant_id)->where('is_default', true)->firstOrFail();
        $stock = ItemLocationStock::where('item_id', $item->id)->where('location_id', $location->id)->firstOrFail();
        $before = $stock->quantity;

        $this->service->move(new MovementDTO(
            tenantId: $item->tenant_id,
            itemId: $item->id,
            variantId: null,
            locationId: $location->id,
            type: InventoryMovementType::PURCHASE_RECEIPT,
            quantityChanged: 10,
            unitCost: 5.00,
        ));

        $stock->refresh();
        $this->assertSame($before + 10, $stock->quantity);
        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $item->tenant_id,
            'item_id' => $item->id,
            'type' => InventoryMovementType::PURCHASE_RECEIPT,
            'quantity_delta' => 10,
            'quantity_after' => $before + 10,
        ]);
    }

    public function test_move_decreases_stock(): void
    {
        $item = Item::where('type', '!=', 'service')->firstOrFail();
        $location = Location::where('tenant_id', $item->tenant_id)->where('is_default', true)->firstOrFail();
        $stock = ItemLocationStock::where('item_id', $item->id)->where('location_id', $location->id)->firstOrFail();
        $stock->update(['quantity' => 20]);

        $this->service->move(new MovementDTO(
            tenantId: $item->tenant_id,
            itemId: $item->id,
            variantId: null,
            locationId: $location->id,
            type: InventoryMovementType::SALE,
            quantityChanged: 5,
        ));

        $stock->refresh();
        $this->assertSame(15, $stock->quantity);
        $this->assertDatabaseHas('stock_movements', [
            'tenant_id' => $item->tenant_id,
            'item_id' => $item->id,
            'type' => InventoryMovementType::SALE,
            'quantity_delta' => -5,
            'quantity_after' => 15,
        ]);
    }

    public function test_move_fails_when_insufficient_stock(): void
    {
        $item = Item::where('type', '!=', 'service')->firstOrFail();
        $location = Location::where('tenant_id', $item->tenant_id)->where('is_default', true)->firstOrFail();
        $stock = ItemLocationStock::where('item_id', $item->id)->where('location_id', $location->id)->firstOrFail();
        $stock->update(['quantity' => 2]);

        $this->expectException(\RuntimeException::class);

        $this->service->move(new MovementDTO(
            tenantId: $item->tenant_id,
            itemId: $item->id,
            variantId: null,
            locationId: $location->id,
            type: InventoryMovementType::SALE,
            quantityChanged: 5,
        ));
    }

    public function test_move_is_idempotent(): void
    {
        $item = Item::where('type', '!=', 'service')->firstOrFail();
        $location = Location::where('tenant_id', $item->tenant_id)->where('is_default', true)->firstOrFail();
        $stock = ItemLocationStock::where('item_id', $item->id)->where('location_id', $location->id)->firstOrFail();
        $stock->update(['quantity' => 20]);

        $key = 'idem-' . uniqid();

        $this->service->move(new MovementDTO(
            tenantId: $item->tenant_id,
            itemId: $item->id,
            variantId: null,
            locationId: $location->id,
            type: InventoryMovementType::SALE,
            quantityChanged: 3,
            idempotencyKey: $key,
        ));

        $this->service->move(new MovementDTO(
            tenantId: $item->tenant_id,
            itemId: $item->id,
            variantId: null,
            locationId: $location->id,
            type: InventoryMovementType::SALE,
            quantityChanged: 3,
            idempotencyKey: $key,
        ));

        $stock->refresh();
        $this->assertSame(17, $stock->quantity);
        $this->assertSame(1, \DB::table('stock_movements')->where('idempotency_key', $key)->count());
    }

    public function test_available_quantity_excludes_reserved(): void
    {
        $item = Item::where('type', '!=', 'service')->firstOrFail();
        $location = Location::where('tenant_id', $item->tenant_id)->where('is_default', true)->firstOrFail();
        $stock = ItemLocationStock::where('item_id', $item->id)->where('location_id', $location->id)->firstOrFail();
        $stock->update(['quantity' => 10, 'reserved_quantity' => 3]);

        $this->assertSame(7, $this->service->available($item->tenant_id, $item->id, null, $location->id));
    }

    public function test_reservation_reduces_available_stock(): void
    {
        $item = Item::where('type', '!=', 'service')->firstOrFail();
        $location = Location::where('tenant_id', $item->tenant_id)->where('is_default', true)->firstOrFail();
        $stock = ItemLocationStock::where('item_id', $item->id)->where('location_id', $location->id)->firstOrFail();
        $stock->update(['quantity' => 10, 'reserved_quantity' => 0]);

        $this->service->reserve(
            tenantId: $item->tenant_id,
            itemId: $item->id,
            variantId: null,
            locationId: $location->id,
            quantity: 4,
            reason: 'Test reservation',
        );

        $stock->refresh();
        $this->assertSame(10, $stock->quantity);
        $this->assertSame(4, $stock->reserved_quantity);
        $this->assertSame(6, $this->service->available($item->tenant_id, $item->id, null, $location->id));
    }

    public function test_releasing_reservation_restores_available_stock(): void
    {
        $item = Item::where('type', '!=', 'service')->firstOrFail();
        $location = Location::where('tenant_id', $item->tenant_id)->where('is_default', true)->firstOrFail();
        $stock = ItemLocationStock::where('item_id', $item->id)->where('location_id', $location->id)->firstOrFail();
        $stock->update(['quantity' => 10, 'reserved_quantity' => 5]);

        $this->service->releaseReservation(
            tenantId: $item->tenant_id,
            itemId: $item->id,
            variantId: null,
            locationId: $location->id,
            quantity: 3,
            reason: 'Release partial',
        );

        $stock->refresh();
        $this->assertSame(2, $stock->reserved_quantity);
    }

    public function test_reservation_fails_when_not_enough_available_stock(): void
    {
        $item = Item::where('type', '!=', 'service')->firstOrFail();
        $location = Location::where('tenant_id', $item->tenant_id)->where('is_default', true)->firstOrFail();
        $stock = ItemLocationStock::where('item_id', $item->id)->where('location_id', $location->id)->firstOrFail();
        $stock->update(['quantity' => 2, 'reserved_quantity' => 0]);

        $this->expectException(\RuntimeException::class);

        $this->service->reserve(
            tenantId: $item->tenant_id,
            itemId: $item->id,
            variantId: null,
            locationId: $location->id,
            quantity: 5,
            reason: 'Should fail',
        );
    }
}
