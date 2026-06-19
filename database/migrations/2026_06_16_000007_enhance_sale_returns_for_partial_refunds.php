<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_returns', function (Blueprint $table): void {
            if (! Schema::hasColumn('sale_returns', 'refund_scope')) {
                $table->string('refund_scope', 24)->default('full')->after('refund_method');
            }
            if (! Schema::hasColumn('sale_returns', 'stock_disposition')) {
                $table->string('stock_disposition', 32)->default('restocked')->after('restock');
            }
            if (! Schema::hasColumn('sale_returns', 'metadata')) {
                $table->json('metadata')->nullable()->after('idempotency_key');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sale_returns', function (Blueprint $table): void {
            foreach (['refund_scope', 'stock_disposition', 'metadata'] as $column) {
                if (Schema::hasColumn('sale_returns', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
