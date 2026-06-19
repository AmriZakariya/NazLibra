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
                $indexName = "{$table}_tenant_updated_at_index";
                // Skip if already exists (safe re-run).
                $existing = collect(\DB::select("SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='{$table}'"))
                    ->pluck('name')->all();
                if (! in_array($indexName, $existing, true)) {
                    $t->index(['tenant_id', 'updated_at'], $indexName);
                }
            });
        }

        // sale_items: explicit index on sale_id for JOIN-heavy dashboard queries.
        if (Schema::hasTable('sale_items') && ! $this->indexExists('sale_items', 'sale_items_sale_id_index')) {
            Schema::table('sale_items', function (Blueprint $t) {
                $t->index('sale_id', 'sale_items_sale_id_index');
            });
        }

        // item_location_stocks: [item_id, location_id] for stock lookup during sale.
        if (Schema::hasTable('item_location_stocks') && ! $this->indexExists('item_location_stocks', 'ils_item_location_index')) {
            Schema::table('item_location_stocks', function (Blueprint $t) {
                $t->index(['item_id', 'location_id'], 'ils_item_location_index');
            });
        }

        // stock_movements: [tenant_id, item_id, location_id] for movement history.
        if (Schema::hasTable('stock_movements') && ! $this->indexExists('stock_movements', 'sm_tenant_item_location_index')) {
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

        Schema::table('sale_items', fn (Blueprint $t) => $t->dropIndex('sale_items_sale_id_index'));
        Schema::table('item_location_stocks', fn (Blueprint $t) => $t->dropIndex('ils_item_location_index'));
        Schema::table('stock_movements', fn (Blueprint $t) => $t->dropIndex('sm_tenant_item_location_index'));
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $indexes = \DB::select("SELECT name FROM sqlite_master WHERE type='index' AND tbl_name=?", [$table]);
        return in_array($indexName, array_column($indexes, 'name'), true);
    }
};
