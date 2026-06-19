<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            if (! Schema::hasColumn('sales', 'source_invoice_id')) {
                $table->foreignId('source_invoice_id')
                    ->nullable()
                    ->after('user_id')
                    ->constrained('invoices')
                    ->nullOnDelete();
                $table->unique(['tenant_id', 'source_invoice_id']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            if (Schema::hasColumn('sales', 'source_invoice_id')) {
                $table->dropUnique(['tenant_id', 'source_invoice_id']);
                $table->dropConstrainedForeignId('source_invoice_id');
            }
        });
    }
};
