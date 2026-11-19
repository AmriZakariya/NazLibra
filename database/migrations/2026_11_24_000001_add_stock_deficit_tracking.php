<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock shows its real value, negative included. The layers never go below
 * zero (valuation and FIFO/LIFO stay untouched); what an outgoing movement
 * took that no layer had is the DEFICIT:
 *
 *   item_location_stock.quantity = SUM(layers remaining) - deficit_quantity
 *
 * On the movements, the deficit is tracked so the next incoming stock can
 * settle it and correct the cost of the sale that took it:
 *   outgoing: deficit_quantity (the shortfall), deficit_settled_quantity (the
 *             part already settled), deficit_unit_cost (what was booked per unit);
 *   incoming: settled_quantity (the part of it that paid a deficit instead of
 *             becoming shelf stock, so it never enters the stock value).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('item_location_stock', 'deficit_quantity')) {
            Schema::table('item_location_stock', function (Blueprint $table): void {
                $table->decimal('deficit_quantity', 15, 4)->default(0);
            });
        }

        if (! Schema::hasColumn('stock_movements', 'deficit_quantity')) {
            Schema::table('stock_movements', function (Blueprint $table): void {
                $table->decimal('deficit_quantity', 15, 4)->default(0);
                $table->decimal('deficit_settled_quantity', 15, 4)->default(0);
                $table->decimal('deficit_unit_cost', 15, 4)->default(0);
                $table->decimal('settled_quantity', 15, 4)->default(0);
            });
        }
    }

    public function down(): void
    {
        // Dropping would lose what is owed to the shelf: left as is.
    }
};
