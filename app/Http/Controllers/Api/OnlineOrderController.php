<?php

namespace App\Http\Controllers\Api;

use App\Models\OnlineOrder;
use App\Models\Sale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OnlineOrderController extends Controller
{
    /**
     * GET /api/v1/online-orders
     *
     * Paginated list of online orders for the authenticated tenant.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var \App\Models\Tenant $tenant */
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
     *
     * Full detail of one online order.
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
     *
     * Update the status of an online order.
     * Allowed statuses: pending, confirmed, preparing, ready, dispatched, delivered, cancelled
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
     * Convert the online order to a POS sale.
     * Returns the newly created sale ID so the client can navigate to it.
     */
    public function convertToSale(Request $request, OnlineOrder $order): JsonResponse
    {
        $tenant = $request->attributes->get('api_tenant');
        abort_if($order->tenant_id !== $tenant->id, 403);

        if ($order->converted_sale_id !== null) {
            return response()->json([
                'ok'      => true,
                'sale_id' => $order->converted_sale_id,
                'message' => 'Already converted.',
            ]);
        }

        $order->load('items');

        $sale = Sale::create([
            'tenant_id'       => $tenant->id,
            'user_id'         => $request->user()?->id,
            'user_name'       => $request->user()?->name,
            'contact_id'      => $order->contact_id,
            'status'          => 'completed',
            'payment_method'  => 'cash',
            'subtotal_amount' => $order->subtotal_amount,
            'discount_amount' => $order->discount_amount,
            'total_amount'    => $order->total_amount,
            'note'            => $order->customer_note,
            'idempotency_key' => 'online-order-' . $order->id,
            'sold_at'         => now(),
        ]);

        foreach ($order->items as $item) {
            $sale->items()->create([
                'item_id'        => $item->item_id,
                'title'          => $item->name,
                'quantity'       => $item->quantity,
                'unit_price'     => $item->unit_price,
                'discount_amount'=> $item->discount_amount,
                'total_amount'   => $item->total_amount,
            ]);
        }

        $order->update([
            'converted_sale_id' => $sale->id,
            'converted_by'      => $request->user()?->id,
            'converted_at'      => now(),
            'status'            => 'confirmed',
        ]);

        return response()->json([
            'ok'      => true,
            'sale_id' => $sale->id,
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
