<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('sale_invoices')
            ->orderBy('id')
            ->chunkById(200, function ($invoices): void {
                foreach ($invoices as $invoice) {
                    $sale = DB::table('sales')->where('id', $invoice->sale_id)->first();
                    if (! $sale) {
                        continue;
                    }

                    $paid = (float) DB::table('sale_payments')
                        ->where('sale_id', $sale->id)
                        ->sum('amount');

                    $metadata = json_decode((string) $sale->metadata, true);
                    if (is_array($metadata) && isset($metadata['paid_amount'])) {
                        $paid = max($paid, (float) $metadata['paid_amount']);
                    }

                    if ($paid <= 0.001 && $sale->status === 'paid') {
                        $paid = (float) $sale->total_amount;
                    }

                    $paid = min(round($paid, 2), (float) $sale->total_amount);
                    $status = match ($sale->status) {
                        'cancelled' => 'cancelled',
                        'refunded' => 'refunded',
                        default => $paid + 0.001 >= (float) $sale->total_amount
                            ? 'paid'
                            : ($invoice->due_date && Carbon::parse($invoice->due_date)->toDateString() < now()->toDateString()
                                ? 'overdue'
                                : ($paid > 0.001 ? 'partial' : 'unpaid')),
                    };

                    DB::table('sale_invoices')->where('id', $invoice->id)->update([
                        'status' => $status,
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        DB::table('sale_invoices')->update(['status' => 'issued']);
    }
};
