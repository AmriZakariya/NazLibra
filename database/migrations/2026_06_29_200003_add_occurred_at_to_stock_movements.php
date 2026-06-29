<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add temporal and COGS tracking to stock_movements.
 *
 * occurred_at: when the operation ACTUALLY happened (device time for offline
 *              operations; server time for online). This is the authoritative
 *              timestamp for inventory valuation ordering — NOT created_at.
 *
 * synced_at:   when the triggering document was received by the backend.
 *              NULL means the operation happened online in real-time.
 *              Non-NULL means this movement came from an offline sync.
 *
 * cogs:        cost of goods sold for outgoing movements, computed after
 *              LIFO layer consumption. NULL for incoming movements.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            // occurred_at defaults to the current time for online ops.
            // Offline ops will override this with the device's sold_at / occurred_at.
            $table->dateTime('occurred_at')->useCurrent()->after('idempotency_key');

            // NULL = created online. Non-null = arrived from offline sync.
            $table->dateTime('synced_at')->nullable()->after('occurred_at');

            // COGS computed after LIFO layer consumption (outgoing only).
            $table->decimal('cogs', 15, 4)->nullable()->after('total_cost');

            // Composite index for rebuild queries:
            // "all movements for this item/location ordered by occurred_at"
            $table->index(
                ['tenant_id', 'item_id', 'location_id', 'occurred_at', 'id'],
                'sm_occurred_rebuild_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex('sm_occurred_rebuild_idx');
            $table->dropColumn(['occurred_at', 'synced_at', 'cogs']);
        });
    }
};
