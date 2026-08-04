<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The dashboard and statistics run many `WHERE tenant_id = ? AND sold_at BETWEEN
 * ? AND ?` queries against `sales`, but the table only indexed `number` and the
 * implicit `tenant_id` FK — so every rollup scanned all of a tenant's sales.
 * A composite (tenant_id, sold_at) makes the tenant slice + date range an index
 * range scan. Works on both MySQL and SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales')) {
            return;
        }

        if (! $this->hasIndex('sales', 'sales_tenant_id_sold_at_index')) {
            Schema::table('sales', function (Blueprint $table): void {
                $table->index(['tenant_id', 'sold_at']);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('sales')) {
            return;
        }

        if ($this->hasIndex('sales', 'sales_tenant_id_sold_at_index')) {
            Schema::table('sales', function (Blueprint $table): void {
                $table->dropIndex('sales_tenant_id_sold_at_index');
            });
        }
    }

    private function hasIndex(string $table, string $index): bool
    {
        foreach (Schema::getIndexes($table) as $existing) {
            if (($existing['name'] ?? null) === $index) {
                return true;
            }
        }

        return false;
    }
};
