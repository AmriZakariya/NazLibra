<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('number');
            $table->decimal('amount', 12, 2);
            $table->string('payment_method')->default('cash');
            $table->string('reference')->nullable();
            $table->string('status')->default('active');
            $table->text('note')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('paid_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'contact_id', 'paid_at']);
            $table->index(['tenant_id', 'status', 'payment_method']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_advances');
    }
};
