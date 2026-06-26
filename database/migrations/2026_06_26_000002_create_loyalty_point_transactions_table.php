<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_point_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained('sales')->nullOnDelete();

            // 'earned' | 'redeemed' | 'adjusted' | 'reversed'
            $table->string('type', 20);

            // Positive for earned/adjusted-up, negative for redeemed/reversed
            $table->decimal('points_amount', 14, 2);

            // Running balance snapshot after this transaction (avoids full scan)
            $table->decimal('balance_after', 14, 2)->default(0);

            $table->text('note')->nullable();

            // Prevents double-earn on sale retry
            $table->string('idempotency_key', 64)->unique();

            $table->timestamp('recorded_at');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['contact_id', 'recorded_at']);
            $table->index(['tenant_id', 'type']);
            $table->index(['sale_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_point_transactions');
    }
};
