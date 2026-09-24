<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gives a transfer the stages the goods actually go through.
 *
 * A transfer used to be one instant: creating it moved the stock out of the
 * source and into the destination in the same breath. That is only true when
 * both ends are one room apart. When goods travel, there is a stretch where
 * they have left the source and not arrived anywhere — and a shop asking
 * "where is my stock right now?" had nothing to read.
 *
 * Now: brouillon (nothing moved, still editable) → envoyé (out of the source,
 * en transit) → reçu (into the destination). A receipt may be short of what
 * was sent, and the difference is recorded rather than quietly absorbed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->timestamp('sent_at')->nullable()->after('transferred_at');
            $table->foreignId('sent_by')->nullable()->after('sent_at')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable()->after('sent_by');
            $table->foreignId('received_by')->nullable()->after('received_at')
                ->constrained('users')->nullOnDelete();
            $table->text('receipt_note')->nullable()->after('received_by');
        });
    }

    public function down(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sent_by');
            $table->dropConstrainedForeignId('received_by');
            $table->dropColumn(['sent_at', 'received_at', 'receipt_note']);
        });
    }
};
