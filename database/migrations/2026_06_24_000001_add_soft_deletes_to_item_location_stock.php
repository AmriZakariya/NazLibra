<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('item_location_stock') && ! Schema::hasColumn('item_location_stock', 'deleted_at')) {
            Schema::table('item_location_stock', fn (Blueprint $t) => $t->softDeletes());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('item_location_stock', 'deleted_at')) {
            Schema::table('item_location_stock', fn (Blueprint $t) => $t->dropSoftDeletes());
        }
    }
};
