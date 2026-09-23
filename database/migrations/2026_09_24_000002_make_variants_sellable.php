<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The link that makes a variant countable: which one was sold.
 *
 * `item_location_stock`, `stock_movements`, `inventory_layers` and
 * `stocktake_items` all carried `variant_id` already. `sale_items` did not, so
 * a shop could hold separate stock for S, M and L and still had no way to ask
 * how many L it sold. The mobile app went further and dropped variant stock
 * out of its sync on purpose, because selling it would have decremented the
 * wrong inventory identity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->foreignId('variant_id')->nullable()->after('item_id')
                ->constrained('item_variants')->nullOnDelete();
            $table->index(['variant_id']);
        });

        Schema::table('items', function (Blueprint $table) {
            // Maintained by VariantService, so the till and the catalogue can
            // ask "does this need a chooser?" without a join on every row.
            // A test holds it to the truth.
            $table->unsignedInteger('variant_count')->default(0)->after('stock_quantity');
            $table->index(['tenant_id', 'variant_count']);
        });

        Schema::table('item_variants', function (Blueprint $table) {
            // Null means "inherit from the article". A shirt priced once
            // should not need the same number typed into every size, and a
            // price change should not have to be repeated across them.
            $table->decimal('sale_price_override', 12, 2)->nullable()->after('sale_price');
            $table->decimal('purchase_price_override', 12, 2)->nullable()->after('purchase_price');
            // The combination, as a sorted list of option_value ids. A unique
            // index on it is what stops "Rouge / L" existing twice.
            $table->string('combination_key', 191)->nullable()->after('attributes');

            $table->unique(['item_id', 'combination_key'], 'uniq_variant_combination');
        });
    }

    public function down(): void
    {
        Schema::table('item_variants', function (Blueprint $table) {
            $table->dropUnique('uniq_variant_combination');
            $table->dropColumn(['sale_price_override', 'purchase_price_override', 'combination_key']);
        });

        Schema::table('items', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'variant_count']);
            $table->dropColumn('variant_count');
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropIndex(['variant_id']);
            $table->dropConstrainedForeignId('variant_id');
        });
    }
};
