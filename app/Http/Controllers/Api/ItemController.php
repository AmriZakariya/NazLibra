<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\ItemLocationStock;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Item lookup endpoints for the POS scanner and search.
 *
 * Intended for real-time lookup during a sale, NOT for bulk sync
 * (use GET /api/v1/sync/items for that).
 */
class ItemController extends Controller
{
    /**
     * GET /api/v1/items/search
     *
     * Real-time search by barcode, ISBN, SKU, or title keyword.
     * Returns at most 30 results to stay fast.
     *
     * Query params:
     *   q        Full-text search on title, author, description
     *   barcode  Exact match on barcode / custom_barcode1
     *   isbn     Exact match on ISBN
     *   sku      Exact match on SKU
     */
    public function search(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant     = $request->attributes->get('api_tenant');
        $locationId = $request->attributes->get('api_location_id');

        $barcode = trim((string) $request->query('barcode', ''));
        $isbn    = trim((string) $request->query('isbn', ''));
        $sku     = trim((string) $request->query('sku', ''));
        $q       = trim((string) $request->query('q', ''));

        if ($barcode === '' && $isbn === '' && $sku === '' && $q === '') {
            return response()->json(['ok' => false, 'message' => 'Fournissez au moins un critère de recherche.'], 422);
        }

        $query = Item::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->with(['category:id,name', 'tax:id,name,rate'])
            ->limit(30);

        if ($barcode !== '') {
            // Exact barcode — usually a scanner hit, expect 1 result.
            $query->where(fn ($q2) => $q2
                ->where('barcode', $barcode)
                ->orWhere('custom_barcode1', $barcode));
        } elseif ($isbn !== '') {
            $query->where('isbn', $isbn);
        } elseif ($sku !== '') {
            $query->where('sku', $sku);
        } else {
            $query->where(fn ($q2) => $q2
                ->where('title', 'like', "%{$q}%")
                ->orWhere('barcode', 'like', "%{$q}%")
                ->orWhere('isbn', 'like', "%{$q}%")
                ->orWhere('author', 'like', "%{$q}%"));
        }

        $items = $query->get([
            'id', 'category_id', 'unit_id', 'tax_id', 'type', 'status',
            'title', 'isbn', 'barcode', 'sku', 'custom_barcode1', 'author',
            'sale_price', 'purchase_price', 'stock_quantity',
            'min_stock_threshold', 'images',
        ]);

        // Attach available stock at current location.
        if ($locationId && $items->isNotEmpty()) {
            $stocks = ItemLocationStock::query()
                ->where('tenant_id', $tenant->id)
                ->where('location_id', $locationId)
                ->whereIn('item_id', $items->pluck('id'))
                ->get(['item_id', 'quantity', 'reserved_quantity', 'average_cost'])
                ->keyBy('item_id');

            $items = $items->map(function ($item) use ($stocks) {
                $s = $stocks->get($item->id);
                $item->setAttribute('available_qty', $s
                    ? max(0, (int) $s->quantity - (int) $s->reserved_quantity)
                    : (int) $item->stock_quantity);
                $item->setAttribute('avg_cost', $s ? (float) $s->average_cost : (float) $item->purchase_price);
                return $item;
            });
        }

        return response()->json(['ok' => true, 'items' => $items]);
    }

    /** GET /api/v1/items/{item} — single item detail with stock at location. */
    public function show(Request $request, Item $item): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant     = $request->attributes->get('api_tenant');
        $locationId = $request->attributes->get('api_location_id');

        if ($item->tenant_id !== $tenant->id) {
            return response()->json(['ok' => false, 'message' => 'Article introuvable.'], 404);
        }

        $item->load(['category:id,name', 'brand:id,name', 'unit:id,name', 'tax:id,name,rate', 'variants']);

        if ($locationId) {
            $stock = ItemLocationStock::where('tenant_id', $tenant->id)
                ->where('item_id', $item->id)
                ->where('location_id', $locationId)
                ->first(['quantity', 'reserved_quantity', 'average_cost']);

            $item->setAttribute('available_qty', $stock
                ? max(0, (int) $stock->quantity - (int) $stock->reserved_quantity)
                : (int) $item->stock_quantity);
            $item->setAttribute('avg_cost', $stock ? (float) $stock->average_cost : (float) $item->purchase_price);
        }

        return response()->json(['ok' => true, 'item' => $item]);
    }
}
