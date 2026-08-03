<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Public sign-up requests captured on the marketing site.
        if (! Schema::hasTable('subscriptions')) {
            Schema::create('subscriptions', function (Blueprint $table): void {
                $table->id();
                $table->string('business_name');
                $table->string('activity')->nullable();      // business_mode key or label
                $table->string('currency', 8)->default('MAD');
                $table->string('contact_name');
                $table->string('email');
                $table->string('phone')->nullable();
                $table->string('desired_subdomain');         // sanitized, lower-case
                $table->string('heard_about')->nullable();    // marketing attribution
                $table->string('status', 20)->default('pending'); // pending|approved|rejected
                $table->text('rejection_reason')->nullable();
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('reviewed_at')->nullable();
                $table->json('meta')->nullable();             // ip, user agent, etc.
                $table->timestamps();

                $table->index('status');
                $table->index('desired_subdomain');
            });
        }

        // One provisioned install per approved subscription.
        if (! Schema::hasTable('tenant_installs')) {
            Schema::create('tenant_installs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
                $table->string('subdomain')->unique();
                $table->string('domain');                     // full host
                $table->string('docroot')->nullable();
                $table->string('db_name')->nullable();
                $table->string('db_user')->nullable();
                $table->string('status', 20)->default('queued'); // queued|running|live|failed
                $table->longText('provision_log')->nullable();
                $table->string('commit_sha', 40)->nullable();
                $table->string('owner_email')->nullable();
                $table->timestamp('provisioned_at')->nullable();
                $table->timestamps();

                $table->index('status');
            });
        }

        // Platform-owner flag (the castlitpos.com super-admin). No such concept
        // existed before — tenant roles top out at per-tenant "owner".
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'is_platform_admin')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->boolean('is_platform_admin')->default(false)->after('is_active');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'is_platform_admin')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('is_platform_admin');
            });
        }
        Schema::dropIfExists('tenant_installs');
        Schema::dropIfExists('subscriptions');
    }
};
