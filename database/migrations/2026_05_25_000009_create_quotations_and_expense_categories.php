<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('converted_sale_id')->nullable()->constrained('sales')->nullOnDelete();
            $table->string('number')->index();
            $table->string('status')->default('draft');
            $table->decimal('subtotal_amount', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->timestamp('quoted_at');
            $table->date('expires_at')->nullable();
            $table->json('lines');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'quoted_at']);
            $table->unique(['tenant_id', 'number']);
        });

        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('color', 16)->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->string('number')->nullable()->after('tenant_id')->index();
            $table->string('payment_method')->default('cash')->after('amount');
            $table->string('reference')->nullable()->after('payment_method');
            $table->text('note')->nullable()->after('reference');
            $table->json('metadata')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn(['number', 'payment_method', 'reference', 'note', 'metadata']);
        });

        Schema::dropIfExists('expense_categories');
        Schema::dropIfExists('quotations');
    }
};
