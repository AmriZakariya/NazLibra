<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row each time someone enters a client's verification code — in the app
 * or on the client's web gate — right or wrong. The master only: the code is
 * checked there for every client.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('client_access_events')) {
            return;
        }

        Schema::create('client_access_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_install_id')->nullable()->constrained()->nullOnDelete();
            // As typed, for an attempt on a subdomain that does not exist.
            $table->string('subdomain', 63)->index();
            $table->string('outcome', 16);          // ok | invalid | blocked | unknown
            $table->string('channel', 8);           // app | web
            $table->string('platform', 20)->nullable();
            $table->string('os', 80)->nullable();
            $table->string('app_version', 20)->nullable();
            $table->string('device_id', 64)->nullable();
            $table->string('device_label', 120)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_install_id', 'outcome', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_access_events');
    }
};
