<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * « À partir de » prices: a coloration « dès 300 DH » is a base price, not a
 * fixed one. The till opens the price pad on such an item and refuses to go
 * below the base (a discount is the way to go lower); the catalogue, the
 * online booking page and the till tiles write « dès » before the amount.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('items') && ! Schema::hasColumn('items', 'price_is_from')) {
            Schema::table('items', function (Blueprint $table): void {
                $table->boolean('price_is_from')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('items', 'price_is_from')) {
            Schema::table('items', function (Blueprint $table): void {
                $table->dropColumn('price_is_from');
            });
        }
    }
};
