<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How long a service takes (a cut 30 min, a colour 90): the agenda books
 * that long and the public booking page offers only slots it fits in.
 * Null on goods, and on a service nobody has timed yet (the shop's default).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('items') && ! Schema::hasColumn('items', 'duration_minutes')) {
            Schema::table('items', function (Blueprint $table): void {
                $table->unsignedSmallInteger('duration_minutes')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('items', 'duration_minutes')) {
            Schema::table('items', function (Blueprint $table): void {
                $table->dropColumn('duration_minutes');
            });
        }
    }
};
