<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryMovement;
use App\Models\Item;
use App\Models\ItemLocationStock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    /**
     * GET /api/v1/inventory/summary
     *
     * Returns aggregated inventory statistics for the current tenant/location.
     */
    public function summary(Request $request): JsonResponse
    {
        /** @var \App\Models\Tenant $tenant */
        $tenant     = $request->attributes->get('api_tenant');
        $locationId = $request->attributes->get('api_location_id');

        $stockAgg = ItemLocationStock::where('tenant_id', $tenant->id)
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->selectRaw('
                COUNT(DISTINCT item_id)                AS item_count,
                COALESCE(SUM(quantity), 0)              AS total_volume,
                COALESCE(SUM(quantity * average_cost), 0) AS total_value
            ')
            ->first();

        $lowStockCount = Item::where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->where('type', '!=', 'service')
            ->where('min_stock_threshold', '>', 0)
            ->whereColumn('stock_quantity', '<=', 'min_stock_threshold')
            ->count();

        $outOfStockCount = Item::where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->where('type', '!=', 'service')
            ->where('stock_quantity', '<=', 0)
            ->count();

        return response()->json([
            'ok'      => true,
            'summary' => [
                'item_count'         => (int) $stockAgg->item_count,
                'total_volume'       => (float) $stockAgg->total_volume,
                'total_value'        => (float) $stockAgg->total_value,
                'low_stock_count'    => $lowStockCount,
                'out_of_stock_count' => $outOfStockCount,
            ],
        ]);
    }

    /**
     * GET /api/v1/inventory/movements?item_id={id}&page={n}&per_page={n}
     *
     * Returns paginated stock movements, optionally filtered by item.
     * Ordered newest first.
     */
    public function movements(Request $request): JsonResponse
    {
        /** @var \App\Models\Tenant $tenant */
        $tenant     = $request->attributes->get('api_tenant');
        $locationId = $request->attributes->get('api_location_id');
        $itemId     = $request->query('item_id');
        $perPage    = min((int) $request->query('per_page', 30), 100);

        $movements = InventoryMovement::where('tenant_id', $tenant->id)
            ->when($itemId, fn ($q) => $q->where('item_id', $itemId))
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->with([
                'item:id,title,barcode',
                'user:id,name',
            ])
            ->latest()
            ->paginate($perPage, [
                'id', 'item_id', 'user_id', 'type',
                'quantity_before', 'quantity_delta', 'quantity_after',
                'unit_cost', 'total_cost',
                'reference_type', 'reference_number',
                'note', 'reason', 'created_at',
            ]);

        return response()->json([
            'ok'       => true,
            'movements' => $movements->items(),
            'total'    => $movements->total(),
            'has_more' => $movements->hasMorePages(),
            'page'     => $movements->currentPage(),
        ]);
    }
}
