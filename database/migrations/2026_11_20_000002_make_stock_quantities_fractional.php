<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A bottle that is used 10 ml at a time is stocked as 2.95 bottles. The layers
 * already carried four decimals; the caches and the movement journal were
 * whole numbers, which would have rounded every consumption away.
 *
 * SQLite keeps whatever number it is given in these columns whatever their
 * declared type, so only MySQL needs the change (and rebuilding `items` on
 * SQLite would only put its foreign keys at risk).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('items', function (Blueprint $table): void {
            $table->decimal('stock_quantity', 15, 4)->default(0)->change();
        });
        Schema::table('item_location_stock', function (Blueprint $table): void {
            $table->decimal('quantity', 15, 4)->default(0)->change();
        });
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->decimal('quantity_delta', 15, 4)->change();
            $table->decimal('quantity_after', 15, 4)->change();
            $table->decimal('quantity_before', 15, 4)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Narrowing back would round real stock: left as is.
    }
};
