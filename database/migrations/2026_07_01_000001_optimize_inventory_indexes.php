<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Performance indexes for the inventory ledger.
 *
 * Changes:
 *   1. stock_movements.idempotency_key — replace global unique with a
 *      per-tenant unique so the index lookup matches the WHERE clause
 *      (tenant_id, idempotency_key) used by InventoryLedgerService.
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
            // Drop the old global unique; idempotency is tenant-scoped.
            $table->dropUnique(['idempotency_key']);

            // Tenant-scoped unique: same key can exist for different tenants.
            $table->unique(['tenant_id', 'idempotency_key'], 'sm_tenant_idempotency_unique');

            // Fast lookups by business document (sale, purchase, return…).
            $table->index(['reference_type', 'reference_id'], 'sm_reference_idx');
        });

        // ── inventory_layer_consumptions ───────────────────────────────────
        Schema::table('inventory_layer_consumptions', function (Blueprint $table) {
            // Speeds up rebuildFrom() step 2 (find affected layers) and
            // COGS lookups that join consumptions on both FK columns.
            $table->index(
                ['outgoing_movement_id', 'inventory_layer_id'],
                'ilc_movement_layer_idx'
            );
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
