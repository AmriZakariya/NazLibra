<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Item;
use App\Models\OnlineOrder;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Tenant;
use App\Services\Documents\DocumentNumberGenerator;
use App\Services\Inventory\InventoryMovementType;
use App\Services\Inventory\InventoryService;
use App\Services\Inventory\MovementDTO;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OnlineOrderController extends Controller
{
    public function __construct(private readonly DocumentNumberGenerator $numbers) {}

    /**
     * GET /api/v1/online-orders
     */
    public function index(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('api_tenant');

        $query = OnlineOrder::with(['items'])
            ->where('tenant_id', $tenant->id);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('channel')) {
            $query->where('channel', $request->input('channel'));
        }

        if ($request->filled('q')) {
            $search = '%' . $request->input('q') . '%';
            $query->where(function ($q) use ($search) {
                $q->where('number', 'like', $search)
                  ->orWhere('customer_name', 'like', $search)
                  ->orWhere('customer_phone', 'like', $search);
            });
        }

        $perPage = min((int) $request->input('per_page', 50), 200);
        $page    = max(1, (int) $request->input('page', 1));

        $paginator = $query->orderBy('ordered_at', 'desc')->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'ok'            => true,
            'page'          => $paginator->currentPage(),
            'per_page'      => $paginator->perPage(),
            'total'         => $paginator->total(),
            'has_more'      => $paginator->hasMorePages(),
            'online_orders' => collect($paginator->items())->map(fn ($o) => $this->format($o))->values(),
        ]);
    }

    /**
     * GET /api/v1/online-orders/{order}
     */
    public function show(Request $request, OnlineOrder $order): JsonResponse
    {
        $tenant = $request->attributes->get('api_tenant');
        abort_if($order->tenant_id !== $tenant->id, 403);

        $order->load(['items', 'contact:id,name,phone,email']);

        return response()->json([
            'ok'           => true,
            'online_order' => $this->format($order),
        ]);
    }

    /**
     * PATCH /api/v1/online-orders/{order}/status
     */
    public function updateStatus(Request $request, OnlineOrder $order): JsonResponse
    {
        $tenant = $request->attributes->get('api_tenant');
        abort_if($order->tenant_id !== $tenant->id, 403);

        $data = $request->validate([
            'status' => ['required', 'string', Rule::in([
                'pending', 'confirmed', 'preparing', 'ready', 'dispatched', 'delivered', 'cancelled',
            ])],
        ]);

        $order->update(['status' => $data['status']]);

        return response()->json([
            'ok'     => true,
            'status' => $order->status,
        ]);
    }

    /**
     * POST /api/v1/online-orders/{order}/convert
     *
     * Convert the online order to a sale, mirroring the web checkout flow:
     * proper numbering, stock deduction via InventoryService, SalePayment records,
     * and order status set to "fulfilled".
     *
     * Request body (all optional — defaults to cash/paid):
     *   payment_method: cash|card|transfer|credit  (default: cash)
     *   payment_status: paid|partial|unpaid         (default: paid)
     *   note: string
     */
    public function convertToSale(Request $request, OnlineOrder $order): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('api_tenant');
        abort_if($order->tenant_id !== $tenant->id, 403);

        // If already converted, return the existing sale idempotently.
        if ($order->converted_sale_id !== null) {
            return response()->json([
                'ok'          => true,
                'sale_id'     => $order->converted_sale_id,
                'sale_number' => Sale::find($order->converted_sale_id)?->number,
                'message'     => 'Already converted.',
            ]);
        }

        // Validate status allows conversion (same rule as web).
        if (! in_array($order->status, ['confirmed', 'preparing', 'ready'], true)) {
            $reason = match ($order->status) {
                'pending'   => 'Confirmez d\'abord la précommande avant de créer la vente.',
                'fulfilled' => 'Cette précommande est déjà traitée.',
                'cancelled' => 'Une précommande annulée ne peut pas être convertie en vente.',
                default     => 'Cette précommande ne peut pas être convertie dans son état actuel.',
            };
            return response()->json(['ok' => false, 'message' => $reason], 422);
        }

        $data = $request->validate([
            'payment_method' => ['nullable', 'string', Rule::in(['cash', 'card', 'transfer', 'credit'])],
            'payment_status' => ['nullable', 'string', Rule::in(['paid', 'partial', 'unpaid'])],
            'note'           => ['nullable', 'string', 'max:700'],
        ]);

        $paymentMethod = $data['payment_method'] ?? 'cash';
        $saleStatus    = $data['payment_status'] ?? 'paid';

        try {
            $sale = DB::transaction(function () use ($tenant, $order, $request, $data, $paymentMethod, $saleStatus): Sale {
                // Re-check inside the transaction with a lock.
                $order->load('items');
                $lockedOrder = OnlineOrder::where('tenant_id', $tenant->id)
                    ->whereKey($order->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedOrder->converted_sale_id !== null) {
                    return Sale::findOrFail($lockedOrder->converted_sale_id);
                }

                /** @var InventoryService $inventoryService */
                $inventoryService = app(InventoryService::class);
                $locationId       = $inventoryService->locationIdFromName($tenant->id, null);
                $allowOversell    = (bool) data_get($tenant->settings, 'pos.allow_oversell', false);

                $itemIds = $order->items->pluck('item_id')->filter()->unique()->values();
                $catalogItems = Item::where('tenant_id', $tenant->id)
                    ->whereIn('id', $itemIds)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                // Verify all lines are linked to catalog items.
                foreach ($order->items as $line) {
                    if (! $line->item_id || ! $catalogItems->has($line->item_id)) {
                        throw new \RuntimeException('Une ligne de la précommande n\'est plus liée au catalogue.');
                    }
                }

                $saleNumber = $this->numbers->next(
                    $tenant, 'sale', 'BL',
                    fn ($n) => Sale::where('tenant_id', $tenant->id)->where('number', $n)->exists()
                )['number'];

                $total          = (float) $order->total_amount;
                $paidAmount     = $saleStatus === 'unpaid' ? 0.0 : $total;
                $resolvedStatus = match ($saleStatus) {
                    'unpaid'  => 'unpaid',
                    'partial' => 'partial',
                    default   => 'paid',
                };
                $resolvedPaymentMethod = $saleStatus === 'unpaid' ? 'credit' : $paymentMethod;

                $sale = Sale::create([
                    'tenant_id'             => $tenant->id,
                    'contact_id'            => $order->contact_id,
                    'user_id'               => $request->user()?->id,
                    'source_online_order_id'=> $order->id,
                    'number'                => $saleNumber,
                    'status'                => $resolvedStatus,
                    'payment_method'        => $resolvedPaymentMethod,
                    'subtotal_amount'       => (float) $order->subtotal_amount,
                    'discount_amount'       => (float) $order->discount_amount,
                    'tax_amount'            => 0,
                    'total_amount'          => $total,
                    'sold_at'               => now(),
                    'idempotency_key'       => 'online-order-' . $order->id,
                    'metadata'              => [
                        'source'                       => 'online_order_conversion',
                        'document_flow'                => 'online_order_then_sale',
                        'document_origin'              => 'online_order',
                        'source_online_order_id'       => $order->id,
                        'source_online_order_number'   => $order->number,
                        'paid_amount'                  => $paidAmount,
                        'payments'                     => $saleStatus !== 'unpaid' ? [$paymentMethod => $total] : [],
                        'note'                         => $data['note'] ?? $order->customer_note,
                    ],
                ]);

                // Create sale items + deduct stock.
                foreach ($order->items as $line) {
                    $item = $catalogItems->get($line->item_id);

                    $sale->items()->create([
                        'item_id'    => $item->id,
                        'name'       => $item->title,
                        'quantity'   => $line->quantity,
                        'unit_price' => (float) $line->unit_price,
                        'total_price'=> (float) $line->total_amount,
                        'unit_cost'  => 0,
                        'total_cost' => 0,
                    ]);

                    if ($item->type !== 'service') {
                        $inventoryService->move(new MovementDTO(
                            tenantId: $tenant->id,
                            itemId: $item->id,
                            variantId: null,
                            locationId: $locationId,
                            type: InventoryMovementType::SALE,
                            quantityChanged: -(int) $line->quantity,
                            userId: $request->user()?->id,
                            referenceType: Sale::class,
                            referenceId: $sale->id,
                            referenceNumber: $sale->number,
                            note: 'Conversion commande ' . $order->number,
                            allowNegative: $allowOversell,
                        ));

                        $item->decrement('stock_quantity', $line->quantity);
                        if (! $allowOversell && $item->fresh()->stock_quantity <= 0) {
                            $item->update(['status' => 'out_of_stock']);
                        }
                    }
                }

                // Record payment.
                if ($paidAmount > 0) {
                    $paymentNumber = $this->numbers->next(
                        $tenant, 'payment', 'PAY',
                        fn (string $n) => SalePayment::where('tenant_id', $tenant->id)->where('number', $n)->exists()
                    )['number'];

                    SalePayment::create([
                        'tenant_id'  => $tenant->id,
                        'sale_id'    => $sale->id,
                        'contact_id' => $order->contact_id,
                        'user_id'    => $request->user()?->id,
                        'number'     => $paymentNumber,
                        'method'     => $paymentMethod,
                        'amount'     => $paidAmount,
                        'paid_at'    => $sale->sold_at,
                        'reference'  => $sale->number,
                        'note'       => 'Paiement conversion commande ' . $order->number,
                    ]);
                }

                // Update order to fulfilled.
                $orderMetadata  = $order->metadata ?? [];
                $statusHistory  = collect($orderMetadata['status_history'] ?? [])
                    ->push([
                        'from'           => $order->status,
                        'to'             => 'fulfilled',
                        'payment_status' => $resolvedStatus === 'paid' ? 'paid' : ($resolvedStatus === 'partial' ? 'deposit' : $order->payment_status),
                        'user_id'        => $request->user()?->id,
                        'user_name'      => $request->user()?->name,
                        'at'             => now()->toIso8601String(),
                        'note'           => 'Conversion en vente ' . $sale->number,
                    ])
                    ->take(-30)
                    ->values()
                    ->all();

                $lockedOrder->update([
                    'converted_sale_id' => $sale->id,
                    'converted_by'      => $request->user()?->id,
                    'converted_at'      => now(),
                    'status'            => 'fulfilled',
                    'payment_status'    => $resolvedStatus === 'paid' ? 'paid' : ($resolvedStatus === 'partial' ? 'deposit' : $order->payment_status),
                    'metadata'          => array_merge($orderMetadata, [
                        'status_history'        => $statusHistory,
                        'converted_sale_number' => $sale->number,
                    ]),
                ]);

                return $sale;
            });
        } catch (\RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok'          => true,
            'sale_id'     => $sale->id,
            'sale_number' => $sale->number,
        ], 201);
    }

    private function format(OnlineOrder $order): array
    {
        return [
            'id'               => $order->id,
            'tenant_id'        => $order->tenant_id,
            'contact_id'       => $order->contact_id,
            'user_id'          => $order->user_id,
            'converted_sale_id'=> $order->converted_sale_id,
            'number'           => $order->number,
            'channel'          => $order->channel,
            'status'           => $order->status,
            'payment_status'   => $order->payment_status,
            'customer_name'    => $order->customer_name,
            'customer_phone'   => $order->customer_phone,
            'customer_email'   => $order->customer_email,
            'delivery_address' => $order->delivery_address,
            'ordered_at'       => $order->ordered_at?->toISOString(),
            'expected_at'      => $order->expected_at?->toDateString(),
            'subtotal_amount'  => (float) $order->subtotal_amount,
            'discount_amount'  => (float) $order->discount_amount,
            'deposit_amount'   => (float) $order->deposit_amount,
            'total_amount'     => (float) $order->total_amount,
            'customer_note'    => $order->customer_note,
            'internal_note'    => $order->internal_note,
            'updated_at'       => $order->updated_at->toISOString(),
            'items'            => $order->relationLoaded('items')
                ? $order->items->sortBy('display_order')->values()->map(fn ($item) => [
                    'id'              => $item->id,
                    'item_id'         => $item->item_id,
                    'name'            => $item->name,
                    'code'            => $item->code,
                    'quantity'        => (float) $item->quantity,
                    'unit_price'      => (float) $item->unit_price,
                    'discount_amount' => (float) $item->discount_amount,
                    'total_amount'    => (float) $item->total_amount,
                    'note'            => $item->note,
                    'display_order'   => $item->display_order,
                ])->all()
                : [],
        ];
    }
}
