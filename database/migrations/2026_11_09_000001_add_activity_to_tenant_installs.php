<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each space last did, as its own `castlit:activity` reported it to the
 * master. Apart from `last_action`, which is what the admin last did TO it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_installs', function (Blueprint $table) {
            if (! Schema::hasColumn('tenant_installs', 'activity_at')) {
                $table->timestamp('activity_at')->nullable()->index();
                $table->text('activity')->nullable();
                $table->timestamp('activity_checked_at')->nullable();
                $table->string('activity_error', 500)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenant_installs', function (Blueprint $table) {
            $table->dropIndex(['activity_at']);
            $table->dropColumn(['activity_at', 'activity', 'activity_checked_at', 'activity_error']);
        });
    }
};
