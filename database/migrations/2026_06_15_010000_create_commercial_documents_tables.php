<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('document_type', 40);
            $table->string('prefix', 24);
            $table->unsignedBigInteger('next_number')->default(1);
            $table->json('format')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'document_type', 'prefix']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('location_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('source_estimate_id')->nullable();
            $table->foreignId('duplicated_from_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->string('number');
            $table->string('serial_prefix', 24)->default('FAC');
            $table->unsignedBigInteger('serial_number')->default(0);
            $table->string('status', 32)->default('draft');
            $table->string('currency', 3)->default('MAD');
            $table->date('issue_date');
            $table->date('service_date')->nullable();
            $table->date('due_date')->nullable();
            $table->json('customer_snapshot');
            $table->json('additional_recipients')->nullable();
            $table->json('tax_breakdown')->nullable();
            $table->json('custom_fields')->nullable();
            $table->decimal('gross_subtotal', 14, 2)->default(0);
            $table->decimal('line_discount_total', 14, 2)->default(0);
            $table->string('document_discount_type', 16)->default('fixed');
            $table->decimal('document_discount_value', 14, 2)->default(0);
            $table->decimal('document_discount_total', 14, 2)->default(0);
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('fee_total', 14, 2)->default(0);
            $table->decimal('rounding_total', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->decimal('amount_paid', 14, 2)->default(0);
            $table->decimal('amount_refunded', 14, 2)->default(0);
            $table->decimal('balance_due', 14, 2)->default(0);
            $table->text('customer_message')->nullable();
            $table->text('internal_note')->nullable();
            $table->text('terms')->nullable();
            $table->text('footer')->nullable();
            $table->string('customer_reference')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('archived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status', 'issue_date']);
            $table->index(['tenant_id', 'customer_id']);
            $table->index(['tenant_id', 'due_date']);
            $table->index(['tenant_id', 'archived_at']);
            $table->index(['tenant_id', 'location_id']);
            $table->index(['tenant_id', 'source_estimate_id']);
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('display_order')->default(1);
            $table->string('item_type', 32)->nullable();
            $table->string('sku')->nullable();
            $table->string('barcode')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('quantity', 14, 3);
            $table->string('unit')->nullable();
            $table->decimal('unit_price', 14, 2);
            $table->string('discount_type', 16)->default('fixed');
            $table->decimal('discount_value', 14, 2)->default(0);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('tax_rate', 8, 4)->default(0);
            $table->boolean('tax_inclusive')->default(false);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->text('note')->nullable();
            $table->json('item_snapshot')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'item_id']);
            $table->index(['invoice_id', 'display_order']);
        });

        Schema::create('invoice_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number');
            $table->string('method', 40)->default('cash');
            $table->string('currency', 3)->default('MAD');
            $table->decimal('amount', 14, 2);
            $table->timestamp('paid_at');
            $table->string('reference')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->text('note')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'invoice_id']);
            $table->index(['tenant_id', 'method', 'paid_at']);
        });

        Schema::create('estimates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('location_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('duplicated_from_id')->nullable()->constrained('estimates')->nullOnDelete();
            $table->foreignId('converted_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->string('number');
            $table->string('serial_prefix', 24)->default('DEV');
            $table->unsignedBigInteger('serial_number')->default(0);
            $table->string('status', 32)->default('draft');
            $table->string('currency', 3)->default('MAD');
            $table->date('issue_date');
            $table->date('expiration_date')->nullable();
            $table->date('service_date')->nullable();
            $table->json('customer_snapshot');
            $table->json('tax_breakdown')->nullable();
            $table->json('custom_fields')->nullable();
            $table->decimal('gross_subtotal', 14, 2)->default(0);
            $table->decimal('line_discount_total', 14, 2)->default(0);
            $table->string('document_discount_type', 16)->default('fixed');
            $table->decimal('document_discount_value', 14, 2)->default(0);
            $table->decimal('document_discount_total', 14, 2)->default(0);
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('fee_total', 14, 2)->default(0);
            $table->decimal('rounding_total', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->text('customer_message')->nullable();
            $table->text('internal_note')->nullable();
            $table->text('terms')->nullable();
            $table->text('footer')->nullable();
            $table->string('customer_reference')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('declined_at')->nullable();
            $table->foreignId('declined_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('decline_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->foreignId('converted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('archived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status', 'issue_date']);
            $table->index(['tenant_id', 'customer_id']);
            $table->index(['tenant_id', 'expiration_date']);
            $table->index(['tenant_id', 'archived_at']);
            $table->index(['tenant_id', 'location_id']);
        });

        Schema::create('estimate_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estimate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('display_order')->default(1);
            $table->string('item_type', 32)->nullable();
            $table->string('sku')->nullable();
            $table->string('barcode')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('quantity', 14, 3);
            $table->string('unit')->nullable();
            $table->decimal('unit_price', 14, 2);
            $table->string('discount_type', 16)->default('fixed');
            $table->decimal('discount_value', 14, 2)->default(0);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('tax_rate', 8, 4)->default(0);
            $table->boolean('tax_inclusive')->default(false);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->text('note')->nullable();
            $table->json('item_snapshot')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'item_id']);
            $table->index(['estimate_id', 'display_order']);
        });

        Schema::create('document_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('document_type', 32);
            $table->unsignedBigInteger('document_id');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 64);
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32)->nullable();
            $table->json('changes')->nullable();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'document_type', 'document_id']);
            $table->index(['tenant_id', 'action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_status_histories');
        Schema::dropIfExists('estimate_items');
        Schema::dropIfExists('estimates');
        Schema::dropIfExists('invoice_payments');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('document_sequences');
    }
};
