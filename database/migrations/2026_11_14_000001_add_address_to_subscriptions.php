<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The shop's street address, asked in its own field so nobody puts it in
 * the space's link (« Adresse souhaitée » had shops typing their city).
 * Handed to the new space's company profile when it is created.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('subscriptions', 'address')) {
            return;
        }

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('address', 190)->nullable()->after('city');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('subscriptions', 'address')) {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->dropColumn('address');
            });
        }
    }
};
