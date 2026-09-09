<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two identical unique indexes guard (tenant_id, idempotency_key) on
 * stock_movements: `stock_movements_tenant_idempotency_unique` (2026_06_19)
 * and `sm_tenant_idempotency_unique` (2026_07_01), which added its own without
 * noticing the first.
 *
 * Harmless to correctness — the constraint is simply enforced twice — but every
 * insert maintains both, and a duplicate-key error can surface under either
 * name, which makes the error confusing to handle. Keeps the shorter one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stock_movements')) {
            return;
        }

        $names = collect(Schema::getIndexes('stock_movements'))->pluck('name');
        if (! $names->contains('sm_tenant_idempotency_unique')) {
            // The keeper is absent, so the older index is the only guard and
            // must stay.
            return;
        }
        if (! $names->contains('stock_movements_tenant_idempotency_unique')) {
            return;
        }

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropUnique('stock_movements_tenant_idempotency_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('stock_movements')) {
            return;
        }

        $names = collect(Schema::getIndexes('stock_movements'))->pluck('name');
        if ($names->contains('stock_movements_tenant_idempotency_unique')) {
            return;
        }

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->unique(
                ['tenant_id', 'idempotency_key'],
                'stock_movements_tenant_idempotency_unique',
            );
        });
    }
};
