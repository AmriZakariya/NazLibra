<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The master's view of each client space's upkeep:
 *  - the support login, now random per space (encrypted here, shown to the
 *    platform admin) instead of one shared "admin" password;
 *  - the last nightly database backup;
 *  - the last run of the space's own scheduler (overdue invoices, pruning).
 */
return new class extends Migration
{
    private const COLUMNS = [
        'support_email', 'support_password', 'last_backup_at', 'last_backup_bytes',
        'last_backup_error', 'schedule_ran_at', 'schedule_error',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('tenant_installs')) {
            return;
        }

        Schema::table('tenant_installs', function (Blueprint $table): void {
            if (! Schema::hasColumn('tenant_installs', 'support_email')) {
                $table->string('support_email')->nullable();
            }
            if (! Schema::hasColumn('tenant_installs', 'support_password')) {
                $table->text('support_password')->nullable();
            }
            if (! Schema::hasColumn('tenant_installs', 'last_backup_at')) {
                $table->timestamp('last_backup_at')->nullable();
            }
            if (! Schema::hasColumn('tenant_installs', 'last_backup_bytes')) {
                $table->unsignedBigInteger('last_backup_bytes')->nullable();
            }
            if (! Schema::hasColumn('tenant_installs', 'last_backup_error')) {
                $table->string('last_backup_error', 255)->nullable();
            }
            if (! Schema::hasColumn('tenant_installs', 'schedule_ran_at')) {
                $table->timestamp('schedule_ran_at')->nullable();
            }
            if (! Schema::hasColumn('tenant_installs', 'schedule_error')) {
                $table->string('schedule_error', 255)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenant_installs', function (Blueprint $table): void {
            foreach (self::COLUMNS as $column) {
                if (Schema::hasColumn('tenant_installs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
