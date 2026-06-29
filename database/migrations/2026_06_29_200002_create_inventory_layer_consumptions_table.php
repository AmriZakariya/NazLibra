<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory layer consumptions record exactly which layer(s) were consumed
 * by each outgoing stock movement.
 *
 * This table provides:
 *   - Exact COGS per sale / adjustment / transfer / etc.
 *   - Full audit trail of which cost layers were consumed and when.
 *   - Correct cost reversal on returns (use original consumed unit_cost).
 *   - Rebuild capability: replay consumptions in order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_layer_consumptions', function (Blueprint $table) {
            $table->id();

            // The outgoing movement that consumed this layer portion.
            $table->unsignedBigInteger('outgoing_movement_id');

            // The inventory layer that was consumed.
            $table->unsignedBigInteger('inventory_layer_id');

            // How much was consumed from this layer (may be partial).
            $table->decimal('quantity_consumed', 15, 4);

            // Unit cost denormalized from the layer at consumption time.
            // Never changes — even if the layer is rebuilt.
            $table->decimal('unit_cost', 15, 4);

            // quantity_consumed × unit_cost
            $table->decimal('total_cost', 15, 4);

            $table->timestamps();

            $table->foreign('outgoing_movement_id')
                ->references('id')->on('stock_movements')
                ->cascadeOnDelete();

            $table->foreign('inventory_layer_id')
                ->references('id')->on('inventory_layers')
                ->cascadeOnDelete();

            // COGS lookup for a movement
            $table->index('outgoing_movement_id', 'ilc_movement_idx');
            // Reverse lookup for rebuild (which movements consumed a layer)
            $table->index('inventory_layer_id', 'ilc_layer_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_layer_consumptions');
    }
};
