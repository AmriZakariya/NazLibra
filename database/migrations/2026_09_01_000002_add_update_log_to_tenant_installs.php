<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps the first-deploy (provisioning) log separate from subsequent update
 * logs, so the admin can see each independently on the client detail page.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenant_installs')) {
            return;
        }
        Schema::table('tenant_installs', function (Blueprint $table): void {
            if (! Schema::hasColumn('tenant_installs', 'update_log')) {
                $table->text('update_log')->nullable()->after('provision_log');
            }
            if (! Schema::hasColumn('tenant_installs', 'updated_log_at')) {
                $table->timestamp('updated_log_at')->nullable()->after('update_log');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('tenant_installs')) {
            return;
        }
        Schema::table('tenant_installs', function (Blueprint $table): void {
            foreach (['update_log', 'updated_log_at'] as $col) {
                if (Schema::hasColumn('tenant_installs', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
