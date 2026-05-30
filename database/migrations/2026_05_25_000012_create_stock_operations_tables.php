<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('number');
            $table->string('status')->default('completed');
            $table->string('warehouse')->nullable();
            $table->string('reason')->nullable();
            $table->integer('total_quantity')->default(0);
            $table->json('lines');
            $table->text('note')->nullable();
            $table->timestamp('adjusted_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'adjusted_at']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('number');
            $table->string('status')->default('completed');
            $table->string('store_from')->nullable();
            $table->string('warehouse_from')->nullable();
            $table->string('store_to')->nullable();
            $table->string('warehouse_to')->nullable();
            $table->integer('total_quantity')->default(0);
            $table->json('lines');
            $table->text('note')->nullable();
            $table->timestamp('transferred_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'transferred_at']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('stock_adjustments');
    }
};
