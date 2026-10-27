<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who did the work, and what they earned on it — for barber shops and any
 * shop paying its staff by commission:
 *  - sale_items.staff_user_id: the barber (or seller) on that line;
 *  - users.commission_rate: their percentage of what they sell;
 *  - sales.tip_amount / tip_user_id: a tip left at the till, for one person,
 *    kept out of the sale total (it is not the shop's revenue).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sale_items') && ! Schema::hasColumn('sale_items', 'staff_user_id')) {
            Schema::table('sale_items', function (Blueprint $table): void {
                $table->foreignId('staff_user_id')->nullable()->constrained('users')->nullOnDelete();
            });
        }

        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'commission_rate')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->decimal('commission_rate', 5, 2)->nullable();
            });
        }

        if (Schema::hasTable('sales')) {
            Schema::table('sales', function (Blueprint $table): void {
                if (! Schema::hasColumn('sales', 'tip_amount')) {
                    $table->decimal('tip_amount', 12, 2)->default(0);
                }
                if (! Schema::hasColumn('sales', 'tip_user_id')) {
                    $table->foreignId('tip_user_id')->nullable()->constrained('users')->nullOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('sale_items', 'staff_user_id')) {
            Schema::table('sale_items', fn (Blueprint $t) => $t->dropConstrainedForeignId('staff_user_id'));
        }
        if (Schema::hasColumn('sales', 'tip_user_id')) {
            Schema::table('sales', fn (Blueprint $t) => $t->dropConstrainedForeignId('tip_user_id'));
        }
        if (Schema::hasColumn('sales', 'tip_amount')) {
            Schema::table('sales', fn (Blueprint $t) => $t->dropColumn('tip_amount'));
        }
        if (Schema::hasColumn('users', 'commission_rate')) {
            Schema::table('users', fn (Blueprint $t) => $t->dropColumn('commission_rate'));
        }
    }
};
