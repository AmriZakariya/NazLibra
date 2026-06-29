<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add COGS and server-side sync timestamp to sales.
 *
 * cogs:               Total cost of goods sold for this sale.
 *                     Sum of inventory_layer_consumptions.total_cost for all
 *                     SALE movements attached to this sale.
 *                     Computed and stored after LIFO layer consumption.
 *
 * server_received_at: When the backend received this sale record.
 *                     NULL  = sale was created online in real-time.
 *                     Set   = sale was synced from an offline device.
 *                     Use this (along with sold_at) to detect backdated operations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->decimal('cogs', 15, 4)->nullable()->after('total_amount');
            $table->dateTime('server_received_at')->nullable()->after('sold_at');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['cogs', 'server_received_at']);
        });
    }
};
