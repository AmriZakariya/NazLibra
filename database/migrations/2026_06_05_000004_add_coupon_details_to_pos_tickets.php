<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_tickets', function (Blueprint $table): void {
            $table->string('coupon_code')->nullable()->after('discount_value');
            $table->decimal('coupon_amount', 12, 2)->default(0)->after('coupon_code');
        });
    }

    public function down(): void
    {
        Schema::table('pos_tickets', function (Blueprint $table): void {
            $table->dropColumn(['coupon_code', 'coupon_amount']);
        });
    }
};
