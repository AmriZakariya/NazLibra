<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sub-products: one article sold in several concrete forms.
 *
 * A shop selling a shirt in S, M and L holds three separate piles of stock and
 * wants to know how many of each it sold. `item_variants` already existed with
 * its own stock, and the inventory layer already carried `variant_id` — but
 * `sale_items` did not, so which variant sold was never recorded and the one
 * question worth asking could not be answered.
 *
 * The model, and the rule that keeps it clean:
 *
 *   option_types   "Taille"          — an axis, reusable across articles
 *   option_values  "S", "M", "L"     — the positions on that axis
 *   item_option_types                — which axes THIS article varies on
 *   item_variants                    — one row per combination, own stock
 *   item_variant_values              — which value each variant holds per axis
 *
 * A variant is a thing with its own stock and its own barcode. An option that
 * has no stock of its own ("no ice", "gift wrap") is a MODIFIER and does not
 * belong here — modelling those as variants turns a burger with eight
 * toppings into 256 rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('option_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);                       // Taille, Couleur
            $table->string('presentation', 16)->default('list'); // list | swatch
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id', 'is_active', 'sort_order']);
        });

        Schema::create('option_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('option_type_id')->constrained()->cascadeOnDelete();
            $table->string('value', 80);                      // S, M, L
            $table->string('short_label', 16)->nullable();    // for a tight till button
            $table->string('swatch', 9)->nullable();          // #RRGGBB for colours
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Ordering is meaningful here in a way it is not elsewhere: S, M, L
            // is not alphabetical and a shop expects to see its own order.
            $table->unique(['option_type_id', 'value']);
            $table->index(['tenant_id', 'option_type_id', 'sort_order']);
        });

        // Which axes an article varies on, and in which order they read.
        Schema::create('item_option_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('option_type_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['item_id', 'option_type_id']);
            $table->index(['tenant_id', 'item_id', 'sort_order']);
        });

        // Which value a variant holds on each axis. A pivot rather than the
        // free-form `attributes` JSON already on item_variants, so "how many
        // L did we sell across every article" is a join and not a scan.
        Schema::create('item_variant_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_variant_id')->constrained('item_variants')->cascadeOnDelete();
            $table->foreignId('option_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('option_value_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            // One position per axis: a variant cannot be both S and M.
            $table->unique(['item_variant_id', 'option_type_id']);
            $table->index(['tenant_id', 'option_value_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_variant_values');
        Schema::dropIfExists('item_option_types');
        Schema::dropIfExists('option_values');
        Schema::dropIfExists('option_types');
    }
};
