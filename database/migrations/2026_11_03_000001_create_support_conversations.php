<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Contacter le support": a conversation between a shop (or a visitor who
 * is not a client yet) and the Castl-it-POS team. The master only: every
 * client's app and the public website write to castlitpos.com.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('support_conversations')) {
            Schema::create('support_conversations', function (Blueprint $table): void {
                $table->id();
                // What the device holds: the public id, and a secret only
                // its hash is kept of.
                $table->uuid('public_id')->unique();
                $table->string('token_hash', 64);
                $table->foreignId('tenant_install_id')->nullable()->constrained()->nullOnDelete();
                $table->string('name', 120);
                $table->string('phone', 40)->nullable();
                $table->string('email', 160)->nullable();
                $table->string('user_name', 120)->nullable();
                $table->string('channel', 8);           // app | web
                $table->string('platform', 20)->nullable();
                $table->string('app_version', 20)->nullable();
                $table->string('locale', 8)->nullable();
                $table->string('status', 10)->default('open'); // open | closed
                $table->timestamp('last_message_at')->nullable()->index();
                $table->string('last_message_preview', 190)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('support_messages')) {
            Schema::create('support_messages', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('support_conversation_id')->constrained()->cascadeOnDelete();
                $table->string('sender', 8);            // client | support
                $table->string('author_name', 120)->nullable();
                $table->text('body')->nullable();
                $table->string('attachment_path')->nullable();
                $table->string('attachment_name', 190)->nullable();
                $table->string('attachment_mime', 100)->nullable();
                $table->unsignedInteger('attachment_size')->nullable();
                $table->string('attachment_kind', 8)->nullable(); // image | audio | file
                $table->unsignedSmallInteger('duration_seconds')->nullable();
                // Read by the other side: support reads the client's, and back.
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
                $table->index(['support_conversation_id', 'sender', 'read_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('support_messages');
        Schema::dropIfExists('support_conversations');
    }
};
