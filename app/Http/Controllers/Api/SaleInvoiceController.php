<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\SaleInvoice;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Sale invoice management from the mobile app.
 *
 * A SaleInvoice is a 1-to-1 document attached to a completed sale.
 * The mobile app can list invoices, view details, and generate one
 * for any completed sale that does not yet have an invoice.
 */
class SaleInvoiceController extends Controller
{
    // ── List ─────────────────────────────────────────────────────────────────

    /**
     * GET /api/v1/invoices
     *
     * Paginated list of sale invoices for the tenant, newest first.
     * Supports optional filters: status, contact_id, q (number search).
     */
    public function index(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('api_tenant');

        $q       = trim((string) $request->query('q', ''));
        $status  = $request->query('status');
        $perPage = min((int) ($request->query('per_page') ?: 25), 100);

        $paginated = SaleInvoice::query()
            ->with(['sale:id,number,sold_at,status,total_amount,contact_id', 'contact:id,name,phone,email'])
            ->where('tenant_id', $tenant->id)
            ->when($q !== '', fn ($query) => $query->where(function ($query) use ($q) {
                $query->where('number', 'like', "%{$q}%")
                    ->orWhereHas('sale', fn ($s) => $s->where('number', 'like', "%{$q}%"))
                    ->orWhereHas('contact', fn ($c) => $c->where('name', 'like', "%{$q}%"));
            }))
            ->when($status, fn ($query) => $query->where('status', $status))
            ->latest('issued_at')
            ->latest('id')
            ->paginate($perPage);

        return response()->json([
            'ok'          => true,
            'page'        => $paginated->currentPage(),
            'per_page'    => $paginated->perPage(),
            'total'       => $paginated->total(),
            'has_more'    => $paginated->hasMorePages(),
            'invoices'    => $paginated->items(),
        ]);
    }

    // ── Detail ────────────────────────────────────────────────────────────────

    /**
     * GET /api/v1/invoices/{invoice}
     *
     * Full invoice detail including linked sale items.
     */
    public function show(Request $request, SaleInvoice $invoice): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('api_tenant');

        if ($invoice->tenant_id !== $tenant->id) {
            return response()->json(['ok' => false, 'message' => 'Facture introuvable.'], 404);
        }

        $invoice->loadMissing([
            'sale.items',
            'contact:id,name,phone,email,address,ice,cin',
        ]);

        return response()->json([
            'ok'      => true,
            'invoice' => $this->formatInvoice($invoice),
        ]);
    }

    // ── Generate ──────────────────────────────────────────────────────────────

    /**
     * POST /api/v1/sales/{sale}/invoice
     *
     * Generate (or regenerate) a sale invoice for the given sale.
     * Idempotent: if an invoice already exists it is updated and returned.
     *
     * Request body (all optional):
     *   due_date  string|null  ISO date YYYY-MM-DD
     *   note      string|null
     */
    public function generate(Request $request, Sale $sale): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('api_tenant');

        if ($sale->tenant_id !== $tenant->id) {
            return response()->json(['ok' => false, 'message' => 'Vente introuvable.'], 404);
        }

        $data = $request->validate([
            'due_date' => ['nullable', 'date'],
            'note'     => ['nullable', 'string', 'max:500'],
        ]);

        $invoice = DB::transaction(function () use ($tenant, $sale, $data): SaleInvoice {
            $sale->loadMissing('invoice');

            $invoice = $sale->invoice ?? new SaleInvoice([
                'tenant_id' => $tenant->id,
                'sale_id'   => $sale->id,
                'number'    => $this->nextInvoiceNumber($tenant),
                'issued_at' => now(),
            ]);

            $invoice->fill([
                'contact_id'       => $sale->contact_id,
                'status'           => $this->statusForSale($sale, $data['due_date'] ?? null),
                'due_date'         => $data['due_date'] ?? null,
                'subtotal_amount'  => $sale->subtotal_amount ?? $sale->total_amount,
                'discount_amount'  => $sale->discount_amount ?? 0,
                'tax_amount'       => $sale->tax_amount ?? 0,
                'total_amount'     => $sale->total_amount,
                'note'             => $data['note'] ?? $invoice->note,
            ]);
            $invoice->save();

            return $invoice->fresh(['sale', 'contact']);
        });

        return response()->json([
            'ok'      => true,
            'invoice' => $this->formatInvoice($invoice->load(['sale.items', 'contact'])),
        ], $invoice->wasRecentlyCreated ? 201 : 200);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function formatInvoice(SaleInvoice $invoice): array
    {
        $sale = $invoice->relationLoaded('sale') ? $invoice->sale : null;

        return [
            'id'               => $invoice->id,
            'number'           => $invoice->number,
            'status'           => $invoice->status,
            'issued_at'        => $invoice->issued_at?->toISOString(),
            'due_date'         => $invoice->due_date?->toDateString(),
            'subtotal_amount'  => (float) $invoice->subtotal_amount,
            'discount_amount'  => (float) $invoice->discount_amount,
            'tax_amount'       => (float) $invoice->tax_amount,
            'total_amount'     => (float) $invoice->total_amount,
            'note'             => $invoice->note,
            'created_at'       => $invoice->created_at?->toISOString(),
            'updated_at'       => $invoice->updated_at?->toISOString(),
            'contact'          => $invoice->contact ? [
                'id'    => $invoice->contact->id,
                'name'  => $invoice->contact->name,
                'phone' => $invoice->contact->phone,
                'email' => $invoice->contact->email,
            ] : null,
            'sale'             => $sale ? [
                'id'           => $sale->id,
                'number'       => $sale->number,
                'sold_at'      => $sale->sold_at instanceof \Carbon\Carbon
                    ? $sale->sold_at->toISOString()
                    : $sale->sold_at,
                'status'       => $sale->status,
                'total_amount' => (float) $sale->total_amount,
                'items'        => $sale->relationLoaded('items')
                    ? $sale->items->map(fn ($item) => [
                        'id'             => $item->id,
                        'name'           => $item->name,
                        'quantity'       => (float) $item->quantity,
                        'unit_price'     => (float) $item->unit_price,
                        'total_price'    => (float) $item->total_price,
                        'discount_amount'=> (float) ($item->discount_amount ?? 0),
                    ])->values()->all()
                    : [],
            ] : null,
        ];
    }

    private function nextInvoiceNumber(Tenant $tenant): string
    {
        $max = SaleInvoice::where('tenant_id', $tenant->id)
            ->where('number', 'like', 'FAC%')
            ->pluck('number')
            ->map(fn ($n) => (int) preg_replace('/\D+/', '', (string) $n))
            ->max() ?? 0;

        return 'FAC' . str_pad((string) ($max + 1), 5, '0', STR_PAD_LEFT);
    }

    private function statusForSale(Sale $sale, ?string $dueDate): string
    {
        $saleStatus = $sale->status ?? '';
        if (in_array($saleStatus, ['refunded', 'partially_refunded'], true)) {
            return 'cancelled';
        }
        if ($dueDate && now()->toDateString() > $dueDate) {
            return 'overdue';
        }
        return 'issued';
    }
}
