<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            $table->boolean('online_store_visible')->default(true)->after('checkout_visible')->index();
            $table->index(['tenant_id', 'status', 'is_enabled', 'online_store_visible', 'type'], 'items_online_store_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            $table->dropIndex('items_online_store_lookup_idx');
            $table->dropColumn('online_store_visible');
        });
    }
};
