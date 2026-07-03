<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryLayer;
use App\Models\InventoryMovement;
use App\Models\Item;
use App\Models\ItemLocationStock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        // total_value is computed from inventory_layers — the LIFO ledger is authoritative.
        // Never fallback to item.purchase_price which is a catalog price, not a cost.
        $layerAgg = InventoryLayer::where('tenant_id', $tenant->id)
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->where('remaining_quantity', '>', 0)
            ->selectRaw('COUNT(DISTINCT item_id) AS item_count, SUM(remaining_quantity * unit_cost) AS total_value')
            ->first();

        $stockAgg = DB::table('item_location_stock as ils')
            ->join('items as i', function ($join) {
                $join->on('i.id', '=', 'ils.item_id')
                     ->whereNull('i.deleted_at');
            })
            ->where('ils.tenant_id', $tenant->id)
            ->whereNull('ils.deleted_at')
            ->when($locationId, fn ($q) => $q->where('ils.location_id', $locationId))
            ->selectRaw('COALESCE(SUM(ils.quantity), 0) AS total_volume')
            ->first();

        $alertCounts = DB::table('items')
            ->where('tenant_id', $tenant->id)
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->where('type', '!=', 'service')
            ->selectRaw('
                SUM(CASE WHEN stock_quantity <= 0 THEN 1 ELSE 0 END) AS out_of_stock,
                SUM(CASE WHEN min_stock_threshold > 0 AND stock_quantity > 0 AND stock_quantity <= min_stock_threshold THEN 1 ELSE 0 END) AS low_stock
            ')
            ->first();

        return response()->json([
            'ok'      => true,
            'summary' => [
                'item_count'         => (int) ($layerAgg->item_count ?? 0),
                'total_volume'       => (float) ($stockAgg->total_volume ?? 0),
                'total_value'        => round((float) ($layerAgg->total_value ?? 0), 2),
                'low_stock_count'    => (int) ($alertCounts->low_stock ?? 0),
                'out_of_stock_count' => (int) ($alertCounts->out_of_stock ?? 0),
            ],
        ]);
    }

    /**
     * GET /api/v1/inventory/items?q=&type=&stock=&sort=&page=&per_page=
     *
     * Returns paginated items with live stock data for the online inventory view.
     */
    public function items(Request $request): JsonResponse
    {
        /** @var \App\Models\Tenant $tenant */
        $tenant  = $request->attributes->get('api_tenant');
        $perPage = min((int) $request->query('per_page', 50), 200);
        $query   = trim((string) $request->query('q', ''));
        $type    = $request->query('type');        // supply | service | book | null
        $stock   = $request->query('stock', 'all'); // all | out | low
        $sort    = $request->query('sort', 'title'); // title | stock_asc | stock_desc | value_desc

        $q = Item::where('tenant_id', $tenant->id)
            ->whereIn('status', ['active', 'out_of_stock'])
            ->where('type', '!=', 'service')
            ->where('track_inventory', true)
            ->when($query, function ($q) use ($query) {
                $q->where(function ($inner) use ($query) {
                    $inner->where('title', 'like', "%{$query}%")
                        ->orWhere('barcode', 'like', "%{$query}%")
                        ->orWhere('isbn', 'like', "%{$query}%")
                        ->orWhere('sku', 'like', "%{$query}%")
                        ->orWhere('author', 'like', "%{$query}%");
                });
            })
            ->when($type, fn ($q) => $q->where('type', $type))
            ->when($stock === 'out', fn ($q) => $q->where('type', '!=', 'service')->where('stock_quantity', '<=', 0))
            ->when($stock === 'low', fn ($q) => $q->where('type', '!=', 'service')->where('min_stock_threshold', '>', 0)->whereColumn('stock_quantity', '<=', 'min_stock_threshold'))
            ->when($sort === 'stock_asc',  fn ($q) => $q->orderBy('stock_quantity'))
            ->when($sort === 'stock_desc', fn ($q) => $q->orderByDesc('stock_quantity'))
            ->when($sort === 'value_desc', fn ($q) => $q->orderByRaw('(SELECT COALESCE(SUM(il.remaining_quantity * il.unit_cost), 0) FROM inventory_layers il WHERE il.tenant_id = items.tenant_id AND il.item_id = items.id) DESC'))
            ->when($sort === 'title' || !in_array($sort, ['stock_asc', 'stock_desc', 'value_desc']), fn ($q) => $q->orderBy('title'));

        $paginator = $q->paginate($perPage, [
            'id', 'type', 'title', 'author', 'barcode', 'isbn', 'sku',
            'images', 'sale_price', 'purchase_price',
            'stock_quantity', 'min_stock_threshold',
        ]);

        // Batch-load LIFO stock values from inventory layers for paginated item IDs.
        $itemIds = collect($paginator->items())->pluck('id')->all();
        $layerValues = InventoryLayer::where('tenant_id', $tenant->id)
            ->whereIn('item_id', $itemIds)
            ->groupBy('item_id')
            ->selectRaw('item_id,
                COALESCE(SUM(remaining_quantity * unit_cost), 0) as stock_value,
                CASE WHEN SUM(remaining_quantity) > 0
                     THEN SUM(remaining_quantity * unit_cost) / SUM(remaining_quantity)
                     ELSE 0 END as average_cost')
            ->get()
            ->keyBy('item_id');

        $items = collect($paginator->items())->map(function (Item $item) use ($layerValues) {
            $layer           = $layerValues->get($item->id);
            $layerStockValue = round((float) ($layer?->stock_value ?? 0), 2);
            // Fall back to purchase_price × stock_quantity for items whose layers
            // pre-date the unit_cost tracking (layers have unit_cost = 0).
            $stockValue = $layerStockValue ?: round((float) $item->purchase_price * (float) $item->stock_quantity, 2);
            return [
                'id'                  => $item->id,
                'type'                => $item->type,
                'title'               => $item->title,
                'author'              => $item->author,
                'barcode'             => $item->barcode,
                'isbn'                => $item->isbn,
                'sku'                 => $item->sku,
                'image_url'           => collect($item->images ?? [])->first(),
                'sale_price'          => (float) $item->sale_price,
                'purchase_price'      => (float) $item->purchase_price,
                'stock_quantity'      => (float) $item->stock_quantity,
                'min_stock_threshold' => (float) $item->min_stock_threshold,
                'average_cost'        => round((float) ($layer?->average_cost ?? 0), 4),
                'stock_value'         => $stockValue,
            ];
        });

        return response()->json([
            'ok'      => true,
            'items'   => $items,
            'total'   => $paginator->total(),
            'page'    => $paginator->currentPage(),
            'has_more'=> $paginator->hasMorePages(),
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

        // When filtered by item, include current stock state so the client can
        // display the correct stock value (quantity × average_cost) without a
        // second request.
        $stockSummary = null;
        if ($itemId) {
            // Authoritative values from inventory layers (LIFO ledger).
            $layerRow = InventoryLayer::where('tenant_id', $tenant->id)
                ->where('item_id', $itemId)
                ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
                ->where('remaining_quantity', '>', 0)
                ->selectRaw('SUM(remaining_quantity) as qty, SUM(remaining_quantity * unit_cost) as value')
                ->first();

            $reservedRow = ItemLocationStock::where('tenant_id', $tenant->id)
                ->where('item_id', $itemId)
                ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
                ->selectRaw('SUM(reserved_quantity) as reserved')
                ->first();

            $qty   = (float) ($layerRow?->qty ?? 0);
            $value = round((float) ($layerRow?->value ?? 0), 2);
            $avg   = $qty > 0 ? round($value / $qty, 4) : 0.0;

            $stockSummary = [
                'quantity'     => $qty,
                'reserved'     => (int) ($reservedRow?->reserved ?? 0),
                'average_cost' => $avg,
                'stock_value'  => $value,
            ];
        }

        return response()->json([
            'ok'           => true,
            'movements'    => $movements->items(),
            'total'        => $movements->total(),
            'has_more'     => $movements->hasMorePages(),
            'page'         => $movements->currentPage(),
            'stock_summary' => $stockSummary,
        ]);
    }
}
