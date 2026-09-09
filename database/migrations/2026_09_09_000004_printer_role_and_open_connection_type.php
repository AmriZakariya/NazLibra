<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two changes to `printers`, both driven by the terminals.
 *
 * 1. `connection_type` was an ENUM of ['tcp','bluetooth','usb']. The app now
 *    also has 'pax_internal' — the printer built into a PAX terminal — and
 *    pushing a config for one returned 422. An enum also means a migration
 *    every time a transport is added, and it fails ASYMMETRICALLY: MySQL
 *    rejects an unknown value while SQLite ignores enums entirely, so the
 *    test suite would stay green while production broke. A plain string, with
 *    the allowed set enforced in validation where it can be read and changed.
 *
 * 2. `role` — whether a printer prints the customer's receipt or kitchen prep
 *    tickets. The receipt goes straight to the till's printer; prep tickets
 *    are routed by station. Without this the role lived only on the device and
 *    was lost whenever a terminal reinstalled and pulled its config back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('printers', function (Blueprint $table) {
            $table->string('connection_type', 32)->default('tcp')->change();
        });

        if (! Schema::hasColumn('printers', 'role')) {
            Schema::table('printers', function (Blueprint $table) {
                // 'receipt' is what a single printer in a shop is for, and it
                // keeps every already-configured printer working.
                $table->string('role', 20)->default('receipt')->after('name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('printers', 'role')) {
            Schema::table('printers', function (Blueprint $table) {
                $table->dropColumn('role');
            });
        }

        Schema::table('printers', function (Blueprint $table) {
            $table->enum('connection_type', ['tcp', 'bluetooth', 'usb'])
                ->default('tcp')
                ->change();
        });
    }
};
