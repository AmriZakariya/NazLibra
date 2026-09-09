<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The `tenants.business_mode` default said 'retail'; the application's own
 * default (BusinessMode::defaultKey()) says 'library'.
 *
 * Two sources of truth for "what is a shop before anyone configures it", and
 * they disagreed. 'retail' resolves to the `general` activity, whose ONLY
 * physical item type is 'supply' — so a freshly provisioned tenant could not
 * create a book, a dish, a medication or a garment in the web catalogue at
 * all. The type field simply rejected them.
 *
 * Aligns the column with the application default. Rows already holding the old
 * default are left alone: a tenant that genuinely is a general store must stay
 * one, and this cannot tell the two apart.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('business_mode', 50)->default('library')->change();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('business_mode', 50)->default('retail')->change();
        });
    }
};
