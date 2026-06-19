<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add unit_cost and total_cost to sale_items for accurate historical COGS.
        Schema::table('sale_items', function (Blueprint $table) {
            $table->decimal('unit_cost', 12, 4)->default(0)->after('unit_price');
            $table->decimal('total_cost', 12, 4)->default(0)->after('unit_cost');
        });

        // Backfill from the COGS already stored in sales.metadata.line_adjustments.
        // Best available historical cost for existing rows (SQLite JSON extract).
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement("
                UPDATE sale_items
                SET
                    unit_cost  = COALESCE((
                        SELECT json_extract(adj.value, '$.average_cost')
                        FROM   json_each(json_extract(sales.metadata, '$.line_adjustments')) AS adj
                        WHERE  json_extract(adj.value, '$.item_id') = sale_items.item_id
                        LIMIT  1
                    ), 0),
                    total_cost = COALESCE((
                        SELECT json_extract(adj.value, '$.cogs')
                        FROM   json_each(json_extract(sales.metadata, '$.line_adjustments')) AS adj
                        WHERE  json_extract(adj.value, '$.item_id') = sale_items.item_id
                        LIMIT  1
                    ), 0)
                FROM sales
                WHERE sale_items.sale_id = sales.id
                  AND sales.metadata IS NOT NULL
                  AND json_extract(sales.metadata, '$.line_adjustments') IS NOT NULL
            ");
        } else {
            // MySQL / MariaDB JSON path syntax.
            DB::statement("
                UPDATE sale_items
                JOIN   sales ON sale_items.sale_id = sales.id
                SET
                    sale_items.unit_cost  = COALESCE(
                        JSON_EXTRACT(sales.metadata, CONCAT('$.line_adjustments[',
                            (SELECT seq FROM json_table(sales.metadata, '$.line_adjustments[*]' COLUMNS(
                                seq FOR ORDINALITY,
                                item_id BIGINT PATH '$.item_id'
                            )) AS jt WHERE jt.item_id = sale_items.item_id LIMIT 1
                        ) - 1, '].average_cost')), 0),
                    sale_items.total_cost = COALESCE(
                        JSON_EXTRACT(sales.metadata, CONCAT('$.line_adjustments[',
                            (SELECT seq FROM json_table(sales.metadata, '$.line_adjustments[*]' COLUMNS(
                                seq FOR ORDINALITY,
                                item_id BIGINT PATH '$.item_id'
                            )) AS jt WHERE jt.item_id = sale_items.item_id LIMIT 1
                        ) - 1, '].cogs')), 0)
                WHERE  sales.metadata IS NOT NULL
            ");
        }

        // Fix stock_movements idempotency: change from global unique to tenant-scoped.
        // The old global unique index was added in 2026_06_16_000003 as
        // 'stock_movements_idempotency_key_unique' (Laravel auto-naming).
        if (Schema::hasColumn('stock_movements', 'idempotency_key')) {
            Schema::table('stock_movements', function (Blueprint $table) {
                try {
                    $table->dropUnique('stock_movements_idempotency_key_unique');
                } catch (\Throwable) {
                    // Already dropped or differently named — safe to continue.
                }
                $table->unique(['tenant_id', 'idempotency_key'], 'stock_movements_tenant_idempotency_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn(['unit_cost', 'total_cost']);
        });

        if (Schema::hasColumn('stock_movements', 'idempotency_key')) {
            Schema::table('stock_movements', function (Blueprint $table) {
                try {
                    $table->dropUnique('stock_movements_tenant_idempotency_unique');
                } catch (\Throwable) {
                }
                $table->unique('idempotency_key');
            });
        }
    }
};
