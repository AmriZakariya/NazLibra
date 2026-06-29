<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory layers represent individual batches of incoming stock.
 *
 * Every incoming movement (purchase, adjustment-in, return-restock, transfer-in)
 * creates exactly one layer. Outgoing movements consume layers via LIFO.
 *
 * The layer ledger is the authoritative source for:
 *   - current stock quantity  = SUM(remaining_quantity)
 *   - current inventory value = SUM(remaining_quantity * unit_cost)
 *   - average cost            = inventory_value / quantity
 *   - COGS                    = see inventory_layer_consumptions
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_layers', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->unsignedBigInteger('location_id');

            // The movement that created this layer (PURCHASE_RECEIPT, MANUAL_ADD, etc.)
            $table->unsignedBigInteger('source_movement_id');

            // Quantity when the layer was created vs. what remains after LIFO consumption.
            $table->decimal('original_quantity', 15, 4);
            $table->decimal('remaining_quantity', 15, 4);

            // Unit cost at the time of inflow. Never changes after creation.
            // This is the cost used for COGS calculation.
            $table->decimal('unit_cost', 15, 4)->default(0);

            // When the operation really happened (device time for offline, server time for online).
            // Used for LIFO ordering: DESC = newest layer consumed first.
            $table->dateTime('occurred_at');

            // Set when remaining_quantity reaches 0 — allows fast filtering.
            $table->dateTime('exhausted_at')->nullable();

            $table->timestamps();

            // Foreign key integrity
            $table->foreign('item_id')->references('id')->on('items')->cascadeOnDelete();
            $table->foreign('location_id')->references('id')->on('locations')->cascadeOnDelete();
            $table->foreign('source_movement_id')->references('id')->on('stock_movements')->cascadeOnDelete();

            // LIFO consumption query: non-exhausted layers, newest first.
            $table->index(
                ['tenant_id', 'item_id', 'location_id', 'remaining_quantity', 'occurred_at', 'id'],
                'il_lifo_idx'
            );

            // Rebuild / replay: all layers for an item/location in chronological order.
            $table->index(
                ['tenant_id', 'item_id', 'location_id', 'occurred_at', 'id'],
                'il_rebuild_idx'
            );

            $table->index('source_movement_id', 'il_source_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_layers');
    }
};
