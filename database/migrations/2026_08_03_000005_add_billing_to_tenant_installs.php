<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trial + payment tracking for client installs: when the free trial ends and
 * whether the client has paid. Manual blocking already uses is_enabled.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenant_installs')) {
            return;
        }
        Schema::table('tenant_installs', function (Blueprint $table): void {
            if (! Schema::hasColumn('tenant_installs', 'trial_ends_at')) {
                $table->timestamp('trial_ends_at')->nullable()->after('provisioned_at');
            }
            if (! Schema::hasColumn('tenant_installs', 'paid_at')) {
                $table->timestamp('paid_at')->nullable()->after('trial_ends_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('tenant_installs')) {
            return;
        }
        Schema::table('tenant_installs', function (Blueprint $table): void {
            foreach (['trial_ends_at', 'paid_at'] as $col) {
                if (Schema::hasColumn('tenant_installs', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
