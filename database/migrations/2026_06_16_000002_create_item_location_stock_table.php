<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_location_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('item_variants')->nullOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->integer('quantity')->default(0);
            $table->integer('reserved_quantity')->default(0);
            $table->integer('incoming_quantity')->default(0);
            $table->integer('damaged_quantity')->default(0);
            $table->integer('returned_quantity')->default(0);
            $table->integer('transferred_quantity')->default(0);
            $table->integer('awaiting_confirmation_quantity')->default(0);
            $table->integer('min_stock')->default(0);
            $table->integer('max_stock')->nullable();
            $table->integer('reorder_point')->default(0);
            $table->integer('preferred_stock_level')->nullable();
            $table->decimal('average_cost', 16, 4)->default(0);
            $table->decimal('last_purchase_cost', 16, 4)->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'item_id', 'variant_id', 'location_id'], 'ils_unique_item_variant_location');
            $table->index(['tenant_id', 'location_id']);
            $table->index(['tenant_id', 'item_id', 'variant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_location_stock');
    }
};
