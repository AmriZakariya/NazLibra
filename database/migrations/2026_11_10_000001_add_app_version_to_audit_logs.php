<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which build of the app did it, and on which physical device: an action from
 * the app said only "Dart/3.13 (dart:io)". Kept as columns, not only in the
 * properties, so a space can tell the master which versions its devices run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('audit_logs', 'app_version')) {
                $table->string('app_version', 20)->nullable();
            }
            if (! Schema::hasColumn('audit_logs', 'app_install_id')) {
                $table->string('app_install_id', 64)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropColumn(['app_version', 'app_install_id']);
        });
    }
};
