<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * « Envoyer les journaux au support »: what a phone sends when something
 * went wrong, for the platform admin to read. The master only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('device_reports')) {
            return;
        }
        Schema::create('device_reports', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 12)->unique();   // LOG-7F3K, what the client quotes
            $table->foreignId('tenant_install_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('support_conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name', 120)->nullable();
            $table->string('platform', 20)->nullable();
            $table->string('device', 160)->nullable();
            $table->string('app_version', 20)->nullable();
            $table->text('note')->nullable();
            $table->string('file_path');
            $table->unsignedInteger('size');             // compressed bytes
            $table->timestamps();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_reports');
    }
};
