<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gives a transfer real locations instead of four free-text boxes.
 *
 * The form asked a cashier to TYPE the source and destination, and the
 * resolver fuzzy-matched the name against the location table, falling back to
 * the default location whenever nothing matched. A typo therefore routed
 * stock to the wrong place silently — and when both ends failed to resolve,
 * source and destination became the same location and the transfer moved
 * nothing at all while still reporting itself completed.
 *
 * The name columns stay: they are the snapshot printed on old transfers, and
 * a location renamed or deleted later must not rewrite history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
            // Nullable only because rows written before this exist; every new
            // transfer requires both, enforced in the service.
            $table->foreignId('source_location_id')->nullable()->after('tenant_id')
                ->constrained('locations')->nullOnDelete();
            $table->foreignId('destination_location_id')->nullable()->after('source_location_id')
                ->constrained('locations')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->after('note')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable()->after('created_by');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')
                ->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable()->after('cancelled_by');

            $table->index(['tenant_id', 'source_location_id']);
            $table->index(['tenant_id', 'destination_location_id']);
        });
    }

    public function down(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'source_location_id']);
            $table->dropIndex(['tenant_id', 'destination_location_id']);
            $table->dropConstrainedForeignId('source_location_id');
            $table->dropConstrainedForeignId('destination_location_id');
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancelled_at', 'cancellation_reason']);
        });
    }
};
