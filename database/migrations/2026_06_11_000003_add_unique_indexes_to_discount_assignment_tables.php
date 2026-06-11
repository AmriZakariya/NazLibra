<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('coupon_sale')
            ->whereNotIn('id', function ($query): void {
                $query->selectRaw('MIN(id)')
                    ->from('coupon_sale')
                    ->groupBy('coupon_id', 'sale_id');
            })
            ->delete();

        DB::table('discount_rule_sale')
            ->whereNotIn('id', function ($query): void {
                $query->selectRaw('MIN(id)')
                    ->from('discount_rule_sale')
                    ->groupBy('discount_rule_id', 'sale_id');
            })
            ->delete();

        Schema::table('coupon_sale', function (Blueprint $table): void {
            $table->unique(['coupon_id', 'sale_id'], 'coupon_sale_coupon_sale_unique');
        });

        Schema::table('discount_rule_sale', function (Blueprint $table): void {
            $table->unique(['discount_rule_id', 'sale_id'], 'discount_rule_sale_rule_sale_unique');
        });
    }

    public function down(): void
    {
        Schema::table('discount_rule_sale', function (Blueprint $table): void {
            $table->dropUnique('discount_rule_sale_rule_sale_unique');
        });

        Schema::table('coupon_sale', function (Blueprint $table): void {
            $table->dropUnique('coupon_sale_coupon_sale_unique');
        });
    }
};
