<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Visits to the castlitpos.com landing page, and how far each got towards a
 * sign-up request (opened → started → tried → refused → sent). Written on
 * the master only: client spaces have no marketing page. Kept 90 days.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('marketing_visits')) {
            return;
        }

        Schema::create('marketing_visits', function (Blueprint $table) {
            $table->id();
            $table->string('ip', 45)->nullable();
            $table->char('country', 2)->nullable();
            $table->string('locale', 5)->nullable();
            $table->string('device', 10)->nullable();
            $table->string('campaign_source', 60)->nullable();
            $table->string('campaign_medium', 60)->nullable();
            $table->string('campaign_name', 120)->nullable();
            $table->string('referrer', 120)->nullable();
            $table->string('landing', 120)->nullable();
            $table->string('user_agent', 250)->nullable();
            $table->string('stage', 10)->default('opened');
            $table->string('refused_fields', 200)->nullable();
            $table->foreignId('subscription_id')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index('created_at');
            $table->index(['campaign_source', 'campaign_name']);
            $table->index('subscription_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_visits');
    }
};
