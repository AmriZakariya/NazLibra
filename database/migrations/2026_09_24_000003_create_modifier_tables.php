<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modifiers: options on a sale LINE that are not sub-products.
 *
 * The line that separates these from variants is stock. A variant has its own
 * pile — selling an L decrements L. A modifier does not: "sans glace" changes
 * nothing, "supplément fromage" changes the price and may eat into a
 * different article's stock, but neither is a thing you count on a shelf.
 *
 * Modelling toppings as variants is what turns a burger with eight of them
 * into 256 sub-products; that is the mistake this table exists to avoid.
 *
 *   modifier_groups       "Suppléments", choose 0-3      — the rules
 *   modifiers             "Fromage +5 DH"                — the choices
 *   item_modifier_groups                                 — which apply where
 *   sale_item_modifiers                                  — what was chosen
 *
 * A modifier may point at an ITEM and consume some of it, so a kitchen that
 * sells extra cheese sees its cheese stock fall.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modifier_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);                       // Suppléments, Cuisson
            $table->unsignedSmallInteger('min_select')->default(0);
            // Null means "as many as you like": a burger can take every
            // topping, and a ceiling invented here would be wrong for someone.
            $table->unsignedSmallInteger('max_select')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id', 'is_active', 'sort_order']);
        });

        Schema::create('modifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('modifier_group_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            // Signed: "sans fromage -2 DH" is as real as a supplement.
            $table->decimal('price_delta', 12, 2)->default(0);
            // The article this eats into, when it eats into one. Null for the
            // majority, which only change the price and the ticket.
            $table->foreignId('linked_item_id')->nullable()->constrained('items')->nullOnDelete();
            $table->decimal('consumes_quantity', 12, 3)->default(1);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['modifier_group_id', 'name']);
            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('item_modifier_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('modifier_group_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['item_id', 'modifier_group_id']);
            $table->index(['tenant_id', 'item_id', 'sort_order']);
        });

        Schema::create('sale_item_modifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_item_id')->constrained()->cascadeOnDelete();
            // Nulled rather than cascaded if the modifier is later deleted:
            // what a customer was charged for is a fact, and the snapshot
            // below is what keeps the old ticket readable.
            $table->foreignId('modifier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->decimal('price_delta', 12, 2)->default(0);
            $table->decimal('quantity', 12, 3)->default(1);
            $table->timestamps();

            $table->index(['tenant_id', 'modifier_id']);
            $table->index(['sale_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_item_modifiers');
        Schema::dropIfExists('item_modifier_groups');
        Schema::dropIfExists('modifiers');
        Schema::dropIfExists('modifier_groups');
    }
};
