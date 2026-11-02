<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The owner's first password, kept encrypted like the support login's, so
 * "Renvoyer les accès" can send it again. It used to exist only in the first
 * email, and a resend told the client to use the password "chosen at
 * signup" — a signup that never asks for one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenant_installs') && ! Schema::hasColumn('tenant_installs', 'owner_password')) {
            Schema::table('tenant_installs', function (Blueprint $table): void {
                $table->text('owner_password')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tenant_installs', 'owner_password')) {
            Schema::table('tenant_installs', function (Blueprint $table): void {
                $table->dropColumn('owner_password');
            });
        }
    }
};
