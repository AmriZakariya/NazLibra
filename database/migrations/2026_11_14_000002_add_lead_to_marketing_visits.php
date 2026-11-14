<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The email and business name a visitor gives at the first step of the
 * sign-up form, before the rest: a prospect who stops halfway can still be
 * written to. Kept with the visit, so deleted with it after 90 days.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketing_visits') || Schema::hasColumn('marketing_visits', 'lead_email')) {
            return;
        }

        Schema::table('marketing_visits', function (Blueprint $table) {
            $table->string('lead_email', 190)->nullable()->after('robot_reason');
            $table->string('lead_business', 120)->nullable()->after('lead_email');
            $table->timestamp('lead_at')->nullable()->after('lead_business');
            $table->index('lead_at');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('marketing_visits', 'lead_email')) {
            Schema::table('marketing_visits', function (Blueprint $table) {
                $table->dropIndex(['lead_at']);
                $table->dropColumn(['lead_email', 'lead_business', 'lead_at']);
            });
        }
    }
};
