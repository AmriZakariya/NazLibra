<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Purchase;
use App\Services\Inventory\InventoryLedgerService;
use App\Services\Inventory\InventoryMovementType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseController extends Controller
{
    public function __construct(private readonly InventoryLedgerService $ledger) {}

    /**
     * GET /api/v1/purchases
     *
     * Paginated list of purchase orders for the authenticated tenant.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var \App\Models\Tenant $tenant */
        $tenant = $request->attributes->get('api_tenant');

        $query = Purchase::with([
            'supplier:id,name,phone',
            'items:id,purchase_id,item_id,quantity_ordered,quantity_received,unit_cost',
            'items.item:id,title,barcode,sku',
        ])
            ->where('tenant_id', $tenant->id);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('q')) {
            $search = '%' . $request->input('q') . '%';
            $query->where(function ($q) use ($search) {
                $q->where('number', 'like', $search)
                  ->orWhereHas('supplier', fn ($s) => $s->where('name', 'like', $search));
            });
        }

        if ($request->filled('since')) {
            $query->where('updated_at', '>=', $request->input('since'));
        }

        $perPage = min((int) $request->input('per_page', 50), 200);
        $page    = max(1, (int) $request->input('page', 1));

        $paginator = $query->orderBy('ordered_at', 'desc')->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'ok'       => true,
            'page'     => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total'    => $paginator->total(),
            'has_more' => $paginator->hasMorePages(),
            'purchases' => collect($paginator->items())->map(fn ($p) => $this->format($p))->values(),
        ]);
    }

    /**
     * GET /api/v1/purchases/{purchase}
     *
     * Full detail of one purchase order.
     */
    public function show(Request $request, Purchase $purchase): JsonResponse
    {
        $tenant = $request->attributes->get('api_tenant');

        abort_if($purchase->tenant_id !== $tenant->id, 403);

        $purchase->load([
            'supplier:id,name,phone,email',
            'items.item:id,title,barcode,sku',
            'payments',
            'user:id,name',
        ]);

        return response()->json([
            'ok'       => true,
            'purchase' => $this->format($purchase),
        ]);
    }

    public function receive(Request $request, Purchase $purchase): JsonResponse
    {
        $tenant = $request->attributes->get('api_tenant');

        if ($purchase->tenant_id !== $tenant->id) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }

        if ($purchase->status === 'received') {
            return response()->json(['ok' => false, 'error' => 'already_received'], 422);
        }

        $locationId = (int) $request->header('X-Location-Id', 1);

        // Receive each item line via the ledger service so LIFO layers are
        // created alongside the stock-cache update. Without this, the cache
        // shows positive stock but the layer sum stays at 0, causing a phantom
        // "Correction automatique" movement on every subsequent POS sale.
        DB::transaction(function () use ($purchase, $locationId, $tenant): void {
            foreach ($purchase->items as $item) {
                $qtyToReceive = (float) $item->quantity_ordered - (float) $item->quantity_received;
                if ($qtyToReceive <= 0) continue;

                $this->ledger->createIncomingMovement([
                    'tenantId'        => $tenant->id,
                    'itemId'          => $item->item_id,
                    'variantId'       => null,
                    'locationId'      => $locationId,
                    'type'            => InventoryMovementType::PURCHASE_RECEIPT,
                    'quantity'        => $qtyToReceive,
                    'unitCost'        => (float) $item->unit_cost,
                    'occurredAt'      => now(),
                    'referenceType'   => 'purchase',
                    'referenceId'     => $purchase->id,
                    'referenceNumber' => $purchase->number,
                    'idempotencyKey'  => 'purchase-receive-'.$purchase->id.'-item-'.$item->item_id,
                ]);

                $item->update(['quantity_received' => $item->quantity_ordered]);
            }

            $purchase->update([
                'status'      => 'received',
                'received_at' => now(),
            ]);
        });

        return response()->json(['ok' => true, 'purchase' => $this->format($purchase->fresh())]);
    }

    private function format(Purchase $purchase): array
    {
        return [
            'id'            => $purchase->id,
            'tenant_id'     => $purchase->tenant_id,
            'supplier_id'   => $purchase->supplier_id,
            'supplier_name' => $purchase->supplier?->name,
            'supplier_phone'=> $purchase->supplier?->phone,
            'user_id'       => $purchase->user_id,
            'user_name'     => $purchase->user?->name,
            'number'        => $purchase->number,
            'status'        => $purchase->status,
            'total_amount'  => (float) $purchase->total_amount,
            'ordered_at'    => $purchase->ordered_at?->toDateString(),
            'expected_at'   => $purchase->expected_at?->toDateString(),
            'received_at'   => $purchase->received_at?->toDateString(),
            'note'          => $purchase->metadata['note'] ?? null,
            'updated_at'    => $purchase->updated_at->toISOString(),
            'items'         => $purchase->relationLoaded('items')
                ? $purchase->items->map(fn ($item) => [
                    'id'                => $item->id,
                    'item_id'           => $item->item_id,
                    'item_title'        => $item->item?->title,
                    'item_barcode'      => $item->item?->barcode,
                    'quantity_ordered'  => (float) $item->quantity_ordered,
                    'quantity_received' => (float) $item->quantity_received,
                    'unit_cost'         => (float) $item->unit_cost,
                ])->values()->all()
                : [],
        ];
    }
}
