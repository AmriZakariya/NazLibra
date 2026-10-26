<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a subscription request came from, and where the shop is.
 *
 * Requests arrive through three doors — the website form, the mobile app's
 * public API, and the platform admin's own "Nouveau client" — and nothing
 * recorded which one. Nor where the shop was: the list could not tell a
 * boutique in Casablanca from one in Paris.
 *
 * Columns rather than `meta`, because these are things to filter and count
 * by. Nullable, with the Morocco default applied by the FORMS: a column
 * default would have stamped every request already in the list as Moroccan,
 * including the ones that plainly are not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('source', 20)->nullable()->after('heard_about');
            $table->char('country', 2)->nullable()->after('source');
            $table->string('city', 120)->nullable()->after('country');

            $table->index('source');
            $table->index('country');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropIndex(['country']);
            $table->dropColumn(['source', 'country', 'city']);
        });
    }
};
