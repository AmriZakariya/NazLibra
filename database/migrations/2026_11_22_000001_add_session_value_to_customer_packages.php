<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What one session of a package is worth, frozen when it is sold.
 *
 * A package sold is not revenue (a liability) and its sessions are priced 0
 * when used, so the barber who does a session earned nothing in the team
 * report. The value of a session is the price PAID for the package divided
 * by its sessions; it is kept on the row so a later edit of the package
 * definition, or a refund of another sale, never moves what was agreed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customer_packages') || Schema::hasColumn('customer_packages', 'session_value')) {
            return;
        }

        Schema::table('customer_packages', function (Blueprint $table): void {
            // 4 decimals: 100 / 3 sessions must not lose a cent over the three.
            $table->decimal('session_value', 12, 4)->nullable();
        });

        DB::table('customer_packages')
            ->where('sessions_total', '>', 0)
            ->update(['session_value' => DB::raw('price_paid / sessions_total')]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('customer_packages', 'session_value')) {
            Schema::table('customer_packages', fn (Blueprint $table) => $table->dropColumn('session_value'));
        }
    }
};
