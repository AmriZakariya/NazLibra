<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->index(['tenant_id', 'status', 'is_enabled', 'checkout_visible', 'type'], 'items_pos_lookup_idx');
            $table->index(['tenant_id', 'brand_id', 'type'], 'items_brand_type_idx');
            $table->index(['tenant_id', 'unit_id', 'type'], 'items_unit_type_idx');
            $table->index(['tenant_id', 'tax_id', 'type'], 'items_tax_type_idx');
            $table->index(['tenant_id', 'status', 'type', 'stock_quantity'], 'items_stock_filter_idx');
            $table->index(['tenant_id', 'sale_price'], 'items_sale_price_idx');
            $table->index(['tenant_id', 'custom_barcode1'], 'items_custom_barcode_idx');
            $table->index(['tenant_id', 'author'], 'items_author_idx');
            $table->index(['tenant_id', 'editor'], 'items_editor_idx');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropIndex('items_pos_lookup_idx');
            $table->dropIndex('items_brand_type_idx');
            $table->dropIndex('items_unit_type_idx');
            $table->dropIndex('items_tax_type_idx');
            $table->dropIndex('items_stock_filter_idx');
            $table->dropIndex('items_sale_price_idx');
            $table->dropIndex('items_custom_barcode_idx');
            $table->dropIndex('items_author_idx');
            $table->dropIndex('items_editor_idx');
        });
    }
};
