<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Pivot table for coupons applied to sales
        Schema::create('coupon_sale', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->decimal('amount_applied', 12, 2)->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'coupon_id']);
            $table->index(['tenant_id', 'sale_id']);
            $table->index(['coupon_id', 'sale_id']);
        });

        // Pivot table for discount rules applied to sales
        Schema::create('discount_rule_sale', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('discount_rule_id')->constrained('discount_rules')->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->decimal('amount_applied', 12, 2)->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'discount_rule_id']);
            $table->index(['tenant_id', 'sale_id']);
            $table->index(['discount_rule_id', 'sale_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('discount_rule_sale');
        Schema::dropIfExists('coupon_sale');
    }
};

