<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets the platform admin suspend/enable a client install and track update runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenant_installs')) {
            return;
        }
        Schema::table('tenant_installs', function (Blueprint $table): void {
            if (! Schema::hasColumn('tenant_installs', 'is_enabled')) {
                $table->boolean('is_enabled')->default(true)->after('status');
            }
            if (! Schema::hasColumn('tenant_installs', 'last_action')) {
                $table->string('last_action')->nullable()->after('current_step');
            }
            if (! Schema::hasColumn('tenant_installs', 'updated_version_at')) {
                $table->timestamp('updated_version_at')->nullable()->after('provisioned_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('tenant_installs')) {
            return;
        }
        Schema::table('tenant_installs', function (Blueprint $table): void {
            foreach (['is_enabled', 'last_action', 'updated_version_at'] as $col) {
                if (Schema::hasColumn('tenant_installs', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
