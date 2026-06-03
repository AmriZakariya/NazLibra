<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number');
            $table->string('status')->default('issued');
            $table->timestamp('issued_at')->nullable();
            $table->date('due_date')->nullable();
            $table->decimal('subtotal_amount', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->text('note')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique('sale_id');
            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'issued_at']);
            $table->index(['tenant_id', 'status']);
        });

        $now = now();
        DB::table('sales')
            ->whereNotNull('metadata')
            ->orderBy('id')
            ->chunkById(200, function ($sales) use ($now): void {
                foreach ($sales as $sale) {
                    $metadata = json_decode((string) $sale->metadata, true);
                    if (! is_array($metadata) || empty($metadata['invoice_number'])) {
                        continue;
                    }

                    DB::table('sale_invoices')->insertOrIgnore([
                        'tenant_id' => $sale->tenant_id,
                        'sale_id' => $sale->id,
                        'contact_id' => $sale->contact_id,
                        'user_id' => $metadata['invoice_created_by'] ?? $sale->user_id,
                        'number' => $metadata['invoice_number'],
                        'status' => 'issued',
                        'issued_at' => $metadata['invoice_created_at'] ?? $sale->sold_at,
                        'due_date' => $metadata['invoice_due_date'] ?? $metadata['due_date'] ?? null,
                        'subtotal_amount' => $sale->subtotal_amount,
                        'discount_amount' => $sale->discount_amount,
                        'tax_amount' => $sale->tax_amount,
                        'total_amount' => $sale->total_amount,
                        'note' => $metadata['invoice_note'] ?? null,
                        'metadata' => json_encode(['source' => 'legacy_sale_metadata']),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_invoices');
    }
};
