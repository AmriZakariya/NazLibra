<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('contact_id');
            // 'gave' = business gave goods/credit (client owes more / supplier was paid)
            // 'got'  = business received (client paid / supplier delivered goods)
            $table->string('type');
            $table->decimal('amount', 14, 2);
            $table->text('note')->nullable();
            $table->string('idempotency_key');
            $table->string('request_hash', 64)->nullable();
            $table->timestamp('recorded_at');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('contact_id')->references('id')->on('contacts')->onDelete('cascade');
            $table->index(['contact_id', 'recorded_at']);
            $table->index(['tenant_id', 'updated_at']);
            $table->unique(['tenant_id', 'idempotency_key'], 'contact_transactions_tenant_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_transactions');
    }
};
