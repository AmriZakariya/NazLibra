<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Performance indexes for the inventory ledger.
 *
 * Changes:
 *   1. stock_movements.idempotency_key — replace global unique (if it exists)
 *      with a per-tenant unique so the lookup (tenant_id, idempotency_key)
 *      used by InventoryLedgerService hits an index.
 *
 *   2. stock_movements — add (reference_type, reference_id) index so
 *      saleCogs() and audit lookups by sale/purchase reference are fast.
 *
 *   3. inventory_layer_consumptions — add composite
 *      (outgoing_movement_id, inventory_layer_id) index for the
 *      rebuildFrom() deletion and restoration steps.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── stock_movements ────────────────────────────────────────────────
        Schema::table('stock_movements', function (Blueprint $table) {
            // The global unique was created conditionally in a prior migration,
            // so it may not exist in every environment. Guard before dropping.
            $existingIndexes = collect(Schema::getIndexes('stock_movements'))
                ->pluck('name');

            if ($existingIndexes->contains('stock_movements_idempotency_key_unique')) {
                $table->dropUnique(['idempotency_key']);
            }

            // Tenant-scoped unique: same key can exist for different tenants.
            // Skip if already present (idempotent re-run safety).
            if (! $existingIndexes->contains('sm_tenant_idempotency_unique')) {
                $table->unique(['tenant_id', 'idempotency_key'], 'sm_tenant_idempotency_unique');
            }

            // Fast lookups by business document (sale, purchase, return…).
            if (! $existingIndexes->contains('sm_reference_idx')) {
                $table->index(['reference_type', 'reference_id'], 'sm_reference_idx');
            }
        });

        // ── inventory_layer_consumptions ───────────────────────────────────
        Schema::table('inventory_layer_consumptions', function (Blueprint $table) {
            $existingIndexes = collect(Schema::getIndexes('inventory_layer_consumptions'))
                ->pluck('name');

            if (! $existingIndexes->contains('ilc_movement_layer_idx')) {
                $table->index(
                    ['outgoing_movement_id', 'inventory_layer_id'],
                    'ilc_movement_layer_idx'
                );
            }
        });
    }

    public function down(): void
    {
        Schema::table('inventory_layer_consumptions', function (Blueprint $table) {
            $table->dropIndex('ilc_movement_layer_idx');
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex('sm_reference_idx');
            $table->dropUnique('sm_tenant_idempotency_unique');
            $table->unique('idempotency_key');
        });
    }
};
