<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which ad, post or search brought a sign-up request (utm tags, or the
 * referring site when there are none). Columns rather than `meta`, as with
 * `source`: these are what the requests are filtered and counted by. The rest
 * (utm_content/term, click id, referring site) stays in `meta.campaign`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('subscriptions', 'campaign_source')) {
            return;
        }

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('campaign_source', 60)->nullable()->after('source');
            $table->string('campaign_medium', 60)->nullable()->after('campaign_source');
            $table->string('campaign_name', 120)->nullable()->after('campaign_medium');

            $table->index(['campaign_source', 'campaign_name']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('subscriptions', 'campaign_source')) {
            return;
        }

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex(['campaign_source', 'campaign_name']);
            $table->dropColumn(['campaign_source', 'campaign_medium', 'campaign_name']);
        });
    }
};
