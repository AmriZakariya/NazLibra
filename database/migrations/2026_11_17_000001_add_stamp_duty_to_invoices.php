<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Tunisian stamp duty (« droit de timbre »), copied onto each invoice
 * when it is created: already part of `total` / `total_amount`, kept apart so
 * the PDF can print it as its own line.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['invoices', 'sale_invoices'] as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'stamp_duty')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->decimal('stamp_duty', 14, 2)->default(0);
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['invoices', 'sale_invoices'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'stamp_duty')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropColumn('stamp_duty');
                });
            }
        }
    }
};
