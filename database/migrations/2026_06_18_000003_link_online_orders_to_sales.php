<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->foreignId('source_online_order_id')->nullable()->after('source_invoice_id')->constrained('online_orders')->nullOnDelete();
            $table->unique(['tenant_id', 'source_online_order_id'], 'sales_tenant_online_order_unique');
        });

        Schema::table('online_orders', function (Blueprint $table): void {
            $table->foreignId('converted_sale_id')->nullable()->after('user_id')->constrained('sales')->nullOnDelete();
            $table->foreignId('converted_by')->nullable()->after('converted_sale_id')->constrained('users')->nullOnDelete();
            $table->dateTime('converted_at')->nullable()->after('converted_by');
            $table->unique(['tenant_id', 'converted_sale_id'], 'online_orders_tenant_sale_unique');
        });
    }

    public function down(): void
    {
        Schema::table('online_orders', function (Blueprint $table): void {
            $table->dropUnique('online_orders_tenant_sale_unique');
            $table->dropConstrainedForeignId('converted_by');
            $table->dropConstrainedForeignId('converted_sale_id');
            $table->dropColumn('converted_at');
        });

        Schema::table('sales', function (Blueprint $table): void {
            $table->dropUnique('sales_tenant_online_order_unique');
            $table->dropConstrainedForeignId('source_online_order_id');
        });
    }
};
