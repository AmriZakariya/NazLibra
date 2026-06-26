<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->decimal('loyalty_points', 14, 2)->default(0)->after('fine_balance');
        });

        Schema::table('sales', function (Blueprint $table): void {
            $table->decimal('loyalty_points_earned',   14, 2)->default(0)->after('total_amount');
            $table->decimal('loyalty_points_redeemed', 14, 2)->default(0)->after('loyalty_points_earned');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropColumn('loyalty_points');
        });

        Schema::table('sales', function (Blueprint $table): void {
            $table->dropColumn(['loyalty_points_earned', 'loyalty_points_redeemed']);
        });
    }
};
