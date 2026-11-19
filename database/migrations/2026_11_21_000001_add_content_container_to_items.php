<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What one stocked unit IS (flacon, pot, tube...): « 3,45 flacons (690 ml) »
 * instead of « 3,45 unités ». A key from App\Support\ContentUnits::CONTAINERS.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('items') && ! Schema::hasColumn('items', 'content_container')) {
            Schema::table('items', function (Blueprint $table): void {
                $table->string('content_container', 16)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('items', 'content_container')) {
            Schema::table('items', function (Blueprint $table): void {
                $table->dropColumn('content_container');
            });
        }
    }
};
