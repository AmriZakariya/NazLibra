<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * « Produits utilisés »: the products a service consumes.
 *
 *  - items.content_quantity / content_unit: what ONE stocked unit of a product
 *    contains (a 200 ml bottle). Stock stays in stocked units; the recipe is
 *    written in the content unit and converted at the sale.
 *  - item_components: the recipe. item_id is the service, variant_id (null =
 *    the service itself) lets one variant override the base rows, and rows
 *    sharing a choice_group are alternatives.
 *  - sale_item_consumptions: what a sale line really consumed, snapshotted so a
 *    later change of recipe, capacity or cost never rewrites history.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('items', 'content_quantity')) {
            Schema::table('items', function (Blueprint $table): void {
                $table->decimal('content_quantity', 12, 3)->nullable();
                $table->string('content_unit', 12)->nullable();
            });
        }

        if (! Schema::hasTable('item_components')) {
            Schema::create('item_components', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('item_id');
                $table->unsignedBigInteger('variant_id')->nullable();
                $table->unsignedBigInteger('component_item_id');
                // In the product's content unit (or in stocked units when the
                // product has no capacity). 0 on a variant row = "not used here".
                $table->decimal('quantity', 12, 3)->default(0);
                $table->string('choice_group', 80)->nullable();
                $table->boolean('is_default')->default(false);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->foreign('item_id')->references('id')->on('items')->cascadeOnDelete();
                $table->foreign('component_item_id')->references('id')->on('items')->cascadeOnDelete();
                $table->index(['tenant_id', 'item_id', 'variant_id'], 'item_components_service_idx');
                $table->index(['tenant_id', 'component_item_id'], 'item_components_product_idx');
            });
        }

        if (! Schema::hasTable('sale_item_consumptions')) {
            Schema::create('sale_item_consumptions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('sale_id');
                $table->unsignedBigInteger('sale_item_id');
                $table->unsignedBigInteger('service_item_id')->nullable();
                $table->unsignedBigInteger('component_item_id');
                $table->unsignedBigInteger('location_id')->nullable();
                $table->string('choice_group', 80)->nullable();
                $table->string('content_unit', 12)->nullable();
                // For the whole line (per-unit x line quantity).
                $table->decimal('quantity_content', 15, 4)->default(0);
                $table->decimal('quantity_stock', 15, 4)->default(0);
                // Weighted-average cost of one STOCKED unit when it was used.
                $table->decimal('unit_cost', 15, 4)->default(0);
                $table->decimal('total_cost', 15, 4)->default(0);
                // The part the shelf did not have (recorded, never refused).
                $table->decimal('shortfall_stock', 15, 4)->default(0);
                // Handed back by refunds.
                $table->decimal('returned_stock', 15, 4)->default(0);
                $table->unsignedBigInteger('movement_id')->nullable();
                $table->timestamps();

                $table->foreign('sale_item_id')->references('id')->on('sale_items')->cascadeOnDelete();
                $table->index(['tenant_id', 'sale_id'], 'sale_item_cons_sale_idx');
                $table->index(['tenant_id', 'component_item_id'], 'sale_item_cons_product_idx');
                $table->index(['tenant_id', 'service_item_id'], 'sale_item_cons_service_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_item_consumptions');
        Schema::dropIfExists('item_components');

        if (Schema::hasColumn('items', 'content_quantity')) {
            Schema::table('items', function (Blueprint $table): void {
                $table->dropColumn(['content_quantity', 'content_unit']);
            });
        }
    }
};
