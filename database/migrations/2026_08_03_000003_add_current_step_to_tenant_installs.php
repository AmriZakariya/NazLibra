<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Track the live provisioning step so the admin can follow progress and see
 * exactly where a run stopped if it fails.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenant_installs') && ! Schema::hasColumn('tenant_installs', 'current_step')) {
            Schema::table('tenant_installs', function (Blueprint $table): void {
                $table->string('current_step')->nullable()->after('status');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tenant_installs') && Schema::hasColumn('tenant_installs', 'current_step')) {
            Schema::table('tenant_installs', function (Blueprint $table): void {
                $table->dropColumn('current_step');
            });
        }
    }
};
