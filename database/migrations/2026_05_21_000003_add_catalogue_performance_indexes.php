<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->index(['tenant_id', 'type']);
            $table->index(['tenant_id', 'type', 'status']);
            $table->index(['tenant_id', 'category_id', 'type']);
            $table->index(['tenant_id', 'title']);
            $table->index(['tenant_id', 'sku']);
            $table->index(['tenant_id', 'item_code']);
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'type']);
            $table->dropIndex(['tenant_id', 'type', 'status']);
            $table->dropIndex(['tenant_id', 'category_id', 'type']);
            $table->dropIndex(['tenant_id', 'title']);
            $table->dropIndex(['tenant_id', 'sku']);
            $table->dropIndex(['tenant_id', 'item_code']);
        });
    }
};
