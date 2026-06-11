<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('item_variants', function (Blueprint $table) {
            // Tenant scoping for multi-tenant search/filter
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Product identifiers
            $table->string('sku')->nullable();
            $table->string('isbn')->nullable();

            // Book-specific fields
            $table->string('language', 20)->nullable();
            $table->string('edition', 120)->nullable();
            $table->string('format', 120)->nullable();
            $table->string('publisher', 160)->nullable();
            $table->string('author', 160)->nullable();

            // Stock management
            $table->integer('min_stock_threshold')->default(0);
            $table->enum('status', ['active', 'inactive', 'out_of_stock'])->default('active');
            $table->boolean('is_active')->default(true);

            // Media and notes
            $table->string('image')->nullable();
            $table->text('notes')->nullable();
            $table->integer('sort_order')->default(0);

            // Indexes for production search/filtering
            $table->index(['tenant_id', 'status'], 'idx_variant_tenant_status');
            $table->index(['tenant_id', 'barcode'], 'idx_variant_tenant_barcode');
            $table->index(['tenant_id', 'sku'], 'idx_variant_tenant_sku');
            $table->index(['tenant_id', 'isbn'], 'idx_variant_tenant_isbn');
            $table->index(['tenant_id', 'is_active'], 'idx_variant_tenant_active');
            $table->index(['tenant_id', 'sort_order'], 'idx_variant_tenant_sort');
        });

        // Populate tenant_id for existing records from parent items
        foreach (\App\Models\ItemVariant::whereNull('tenant_id')->with('item')->cursor() as $variant) {
            $variant->update(['tenant_id' => $variant->item->tenant_id]);
        }
    }

    public function down(): void
    {
        Schema::table('item_variants', function (Blueprint $table) {
            $table->dropIndex('idx_variant_tenant_status');
            $table->dropIndex('idx_variant_tenant_barcode');
            $table->dropIndex('idx_variant_tenant_sku');
            $table->dropIndex('idx_variant_tenant_isbn');
            $table->dropIndex('idx_variant_tenant_active');
            $table->dropIndex('idx_variant_tenant_sort');
            $table->dropColumn([
                'tenant_id',
                'sku',
                'isbn',
                'language',
                'edition',
                'format',
                'publisher',
                'author',
                'min_stock_threshold',
                'status',
                'is_active',
                'image',
                'notes',
                'sort_order',
            ]);
        });
    }
};
