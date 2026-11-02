<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every app installation that logged in used to get a terminal of its own,
 * named after the phone ("NazPOS Mobile · E97ED1", code = the install's
 * UUID). Nothing used them, and each reinstall added one more to the shop's
 * list next to its real Terminal Mobile 1 / 2.
 *
 * Sales and movements keep the terminal's name in their own snapshot, and
 * their links are set to null on delete, so removing them loses nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('virtual_devices')) {
            return;
        }

        DB::table('virtual_devices')
            ->where('type', 'mobile')
            ->where('name', 'like', 'NazPOS Mobile%')
            ->get(['id', 'code'])
            ->filter(fn ($row) => preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', (string) $row->code))
            ->each(fn ($row) => DB::table('virtual_devices')->where('id', $row->id)->delete());
    }

    public function down(): void
    {
        // Nothing to restore: they were never meant to exist.
    }
};
