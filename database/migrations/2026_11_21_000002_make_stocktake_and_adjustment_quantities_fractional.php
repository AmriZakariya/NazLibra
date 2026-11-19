<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A bottle used by the ml is counted and corrected in fractions too: 2.95
 * bottles on the shelf. The stock caches and the journal already carry four
 * decimals; the stocktake counts and the adjustment totals were whole numbers.
 *
 * SQLite keeps whatever number it is given whatever the declared type, so only
 * MySQL needs the change.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        if (Schema::hasColumn('stocktake_items', 'counted_quantity')) {
            Schema::table('stocktake_items', function (Blueprint $table): void {
                $table->decimal('expected_quantity', 15, 4)->default(0)->change();
                $table->decimal('counted_quantity', 15, 4)->nullable()->change();
            });
        }
        if (Schema::hasColumn('stock_adjustments', 'total_quantity')) {
            Schema::table('stock_adjustments', function (Blueprint $table): void {
                $table->decimal('total_quantity', 15, 4)->default(0)->change();
            });
        }
    }

    public function down(): void
    {
        // Narrowing back would round real counts: left as is.
    }
};
