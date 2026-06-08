<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_register_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('financial_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('store_key')->nullable()->index();
            $table->string('number')->index();
            $table->string('status')->default('open')->index();
            $table->decimal('opening_amount', 12, 2)->default(0);
            $table->decimal('expected_cash_amount', 12, 2)->default(0);
            $table->decimal('counted_cash_amount', 12, 2)->nullable();
            $table->decimal('difference_amount', 12, 2)->default(0);
            $table->timestamp('opened_at')->nullable()->index();
            $table->timestamp('closed_at')->nullable()->index();
            $table->text('note')->nullable();
            $table->text('closing_note')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'store_key', 'status']);
        });

        Schema::create('cash_register_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cash_register_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('account_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number')->index();
            $table->string('type')->index();
            $table->string('direction')->default('in')->index();
            $table->decimal('amount', 12, 2)->default(0);
            $table->decimal('balance_after', 12, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->string('reference')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('moved_at')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'type', 'moved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_register_movements');
        Schema::dropIfExists('cash_register_sessions');
    }
};
