<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MASTER: which app versions are current, per platform. Below the minimum the
 * app is blocked until updated; below the latest it is offered the update.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('app_update_policies')) {
            return;
        }

        Schema::create('app_update_policies', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 16)->unique();
            $table->string('latest_version', 32)->nullable();
            $table->string('min_version', 32)->nullable();
            $table->text('message')->nullable();
            // Android without Google Play (PAX): an APK kept here, or a link to one.
            $table->string('apk_path')->nullable();
            $table->string('apk_url', 500)->nullable();
            $table->string('apk_version', 32)->nullable();
            $table->unsignedBigInteger('apk_size')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_update_policies');
    }
};
