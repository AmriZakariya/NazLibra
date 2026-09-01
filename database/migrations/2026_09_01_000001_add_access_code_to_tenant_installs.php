<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-client access code. Communicated to the client once the admin approves
 * them; the mobile app checks it (client name + code) before showing login.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenant_installs')) {
            return;
        }
        Schema::table('tenant_installs', function (Blueprint $table): void {
            if (! Schema::hasColumn('tenant_installs', 'access_code')) {
                $table->string('access_code', 32)->nullable()->after('is_enabled');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('tenant_installs')) {
            return;
        }
        Schema::table('tenant_installs', function (Blueprint $table): void {
            if (Schema::hasColumn('tenant_installs', 'access_code')) {
                $table->dropColumn('access_code');
            }
        });
    }
};
