<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** When the team retired a client space (status « archived »). */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenant_installs') && ! Schema::hasColumn('tenant_installs', 'archived_at')) {
            Schema::table('tenant_installs', function (Blueprint $table): void {
                $table->timestamp('archived_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tenant_installs', 'archived_at')) {
            Schema::table('tenant_installs', fn (Blueprint $table) => $table->dropColumn('archived_at'));
        }
    }
};
