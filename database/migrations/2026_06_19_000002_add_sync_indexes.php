<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add composite (tenant_id, updated_at) indexes on every table that the
 * mobile sync endpoints query with ?since=.  Without these, each delta-sync
 * request is a full table scan.
 *
 * Also adds targeted performance indexes for the dashboard and sale queries.
 */
return new class extends Migration
{
    /** Tables that need [tenant_id, updated_at] for delta sync. */
    private array $syncTables = [
        'items',
        'contacts',
        'categories',
        'brands',
        'units',
        'taxes',
        'sales',
    ];

    public function up(): void
    {
        foreach ($this->syncTables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->index(['tenant_id', 'updated_at'], "{$table}_tenant_updated_at_index");
            });
        }

        // sale_items: explicit index on sale_id for JOIN-heavy dashboard queries.
        if (Schema::hasTable('sale_items')) {
            Schema::table('sale_items', function (Blueprint $t) {
                $t->index('sale_id', 'sale_items_sale_id_index');
            });
        }

        // item_location_stock: [item_id, location_id] for stock lookup during sale.
        if (Schema::hasTable('item_location_stock')) {
            Schema::table('item_location_stock', function (Blueprint $t) {
                $t->index(['item_id', 'location_id'], 'ils_item_location_index');
            });
        }

        // stock_movements: [tenant_id, item_id, location_id] for movement history.
        if (Schema::hasTable('stock_movements')) {
            Schema::table('stock_movements', function (Blueprint $t) {
                $t->index(['tenant_id', 'item_id', 'location_id'], 'sm_tenant_item_location_index');
            });
        }
    }

    public function down(): void
    {
        foreach ($this->syncTables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($table) {
                try {
                    $t->dropIndex("{$table}_tenant_updated_at_index");
                } catch (\Throwable) {
                }
            });
        }

        if (Schema::hasTable('sale_items')) {
            Schema::table('sale_items', fn (Blueprint $t) => $t->dropIndex('sale_items_sale_id_index'));
        }
        if (Schema::hasTable('item_location_stock')) {
            Schema::table('item_location_stock', fn (Blueprint $t) => $t->dropIndex('ils_item_location_index'));
        }
        if (Schema::hasTable('stock_movements')) {
            Schema::table('stock_movements', fn (Blueprint $t) => $t->dropIndex('sm_tenant_item_location_index'));
        }
    }
};
