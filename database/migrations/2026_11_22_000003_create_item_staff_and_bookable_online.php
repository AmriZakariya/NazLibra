<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who does which service, and which services the public page offers.
 *
 * - item_staff: a service done only by these people. A service with NO row
 *   is done by everyone, so a shop that never sets it changes nothing.
 * - items.bookable_online: shown on /reserver. Existing rows stay true (what
 *   they were); a new service is set at creation by the shop's activity
 *   (see Item::booted).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('item_staff')) {
            Schema::create('item_staff', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('item_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['item_id', 'user_id']);
                $table->index(['tenant_id', 'user_id']);
            });
        }

        if (Schema::hasTable('items') && ! Schema::hasColumn('items', 'bookable_online')) {
            Schema::table('items', function (Blueprint $table): void {
                $table->boolean('bookable_online')->default(true);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('item_staff');
        if (Schema::hasColumn('items', 'bookable_online')) {
            Schema::table('items', fn (Blueprint $table) => $table->dropColumn('bookable_online'));
        }
    }
};
