<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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

        $stockAgg = DB::table('item_location_stock as ils')
            ->join('items as i', function ($join) {
                $join->on('i.id', '=', 'ils.item_id')
                     ->whereNull('i.deleted_at');
            })
            ->where('ils.tenant_id', $tenant->id)
            ->whereNull('ils.deleted_at')
            ->when($locationId, fn ($q) => $q->where('ils.location_id', $locationId))
            ->selectRaw('
                COUNT(DISTINCT ils.item_id)                                                              AS item_count,
                COALESCE(SUM(ils.quantity), 0)                                                           AS total_volume,
                COALESCE(SUM(ils.quantity * COALESCE(NULLIF(ils.average_cost, 0), i.purchase_price, 0)), 0) AS total_value
            ')
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
                'item_count'         => (int) $stockAgg->item_count,
                'total_volume'       => (float) $stockAgg->total_volume,
                'total_value'        => (float) $stockAgg->total_value,
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
            ->when($sort === 'value_desc', fn ($q) => $q->orderByRaw('stock_quantity * purchase_price DESC'))
            ->when($sort === 'title' || !in_array($sort, ['stock_asc', 'stock_desc', 'value_desc']), fn ($q) => $q->orderBy('title'));

        $paginator = $q->paginate($perPage, [
            'id', 'type', 'title', 'author', 'barcode', 'isbn', 'sku',
            'images', 'sale_price', 'purchase_price',
            'stock_quantity', 'min_stock_threshold',
        ]);

        $items = collect($paginator->items())->map(fn (Item $item) => [
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
        ]);

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

        return response()->json([
            'ok'       => true,
            'movements' => $movements->items(),
            'total'    => $movements->total(),
            'has_more' => $movements->hasMorePages(),
            'page'     => $movements->currentPage(),
        ]);
    }
}
