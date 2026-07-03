<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->boolean('track_inventory')->default(true)->after('min_stock_threshold');
        });

        // Services never need inventory tracking — set to false retroactively.
        DB::table('items')->where('type', 'service')->update(['track_inventory' => false]);
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('track_inventory');
        });
    }
};
