<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $softDeleteTables = ['categories', 'brands', 'units', 'taxes', 'sale_invoices', 'item_location_stock'];

    private array $deltaTables = [
        'items', 'contacts', 'categories', 'brands', 'units', 'taxes',
        'sales', 'sale_invoices', 'contact_transactions',
    ];

    public function up(): void
    {
        foreach ($this->softDeleteTables as $tableName) {
            if (Schema::hasTable($tableName) && ! Schema::hasColumn($tableName, 'deleted_at')) {
                Schema::table($tableName, fn (Blueprint $table) => $table->softDeletes());
            }
        }

        foreach ($this->deltaTables as $tableName) {
            if (Schema::hasTable($tableName)) {
                Schema::table($tableName, fn (Blueprint $table) => $table->index(
                    ['tenant_id', 'updated_at', 'id'],
                    $tableName.'_sync_window_index'
                ));
            }
        }

        if (Schema::hasTable('item_location_stock')) {
            Schema::table('item_location_stock', function (Blueprint $table): void {
                $table->index(['tenant_id', 'location_id', 'updated_at', 'id'], 'item_location_stock_sync_window_index');
                $table->index(['tenant_id', 'item_id', 'location_id'], 'item_location_stock_lookup_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('item_location_stock')) {
            Schema::table('item_location_stock', function (Blueprint $table): void {
                $table->dropIndex('item_location_stock_sync_window_index');
                $table->dropIndex('item_location_stock_lookup_index');
            });
        }

        foreach ($this->deltaTables as $tableName) {
            if (Schema::hasTable($tableName)) {
                Schema::table($tableName, fn (Blueprint $table) => $table->dropIndex($tableName.'_sync_window_index'));
            }
        }

        foreach ($this->softDeleteTables as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'deleted_at')) {
                Schema::table($tableName, fn (Blueprint $table) => $table->dropSoftDeletes());
            }
        }

    }
};
