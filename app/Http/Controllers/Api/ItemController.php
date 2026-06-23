<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\ItemLocationStock;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

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
    #[OA\Get(
        path: '/api/v1/items/search',
        operationId: 'itemSearch',
        summary: 'Real-time item search by barcode, ISBN, SKU, or keyword',
        security: [['bearerAuth' => []]],
        tags: ['Items'],
        parameters: [
            new OA\Parameter(name: 'X-Tenant-Slug', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Location-Id', in: 'header', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'q', in: 'query', required: false, description: 'Keyword search on title/author/barcode/isbn', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'barcode', in: 'query', required: false, description: 'Exact barcode match', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'isbn', in: 'query', required: false, description: 'Exact ISBN match', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sku', in: 'query', required: false, description: 'Exact SKU match', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Matching items (max 30)',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'ok', type: 'boolean', example: true),
                    new OA\Property(property: 'items', type: 'array', items: new OA\Items(type: 'object')),
                ])
            ),
            new OA\Response(response: 422, description: 'No search criteria provided'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
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

    /**
     * POST /api/v1/items — create a new item from the POS app.
     */
    public function store(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant     = $request->attributes->get('api_tenant');
        $locationId = $request->attributes->get('api_location_id');

        $data = $request->validate([
            'local_id'            => ['nullable', 'string', 'max:100'],
            'title'               => ['required', 'string', 'max:255'],
            'sale_price'          => ['required', 'numeric', 'min:0'],
            'purchase_price'      => ['nullable', 'numeric', 'min:0'],
            'stock_quantity'      => ['nullable', 'numeric', 'min:0'],
            'min_stock_threshold' => ['nullable', 'numeric', 'min:0'],
            'isbn'                => ['nullable', 'string', 'max:30'],
            'barcode'             => ['nullable', 'string', 'max:100'],
            'sku'                 => ['nullable', 'string', 'max:100'],
            'author'              => ['nullable', 'string', 'max:255'],
            'category_id'         => ['nullable', 'integer'],
            'type'                => ['nullable', 'string', 'in:product,service,book'],
            'extra_fields'        => ['nullable', 'array'],
        ]);

        // Idempotency: if a local_id is provided, return the existing item if it was
        // already created (handles retries after a lost network response).
        if (!empty($data['local_id'])) {
            $existing = Item::where('tenant_id', $tenant->id)
                ->where('external_id', $data['local_id'])
                ->first();
            if ($existing) {
                return response()->json(['ok' => true, 'item' => $existing->fresh()], 200);
            }
        }

        $item = Item::create([
            'tenant_id'           => $tenant->id,
            'external_id'         => $data['local_id'] ?? null,
            'title'               => $data['title'],
            'sale_price'          => $data['sale_price'],
            'purchase_price'      => $data['purchase_price'] ?? 0,
            'stock_quantity'      => $data['stock_quantity'] ?? 0,
            'min_stock_threshold' => $data['min_stock_threshold'] ?? 0,
            'isbn'                => $data['isbn'] ?? null,
            'barcode'             => $data['barcode'] ?? null,
            'sku'                 => $data['sku'] ?? null,
            'author'              => $data['author'] ?? null,
            'category_id'         => $data['category_id'] ?? null,
            'type'                => $data['type'] ?? 'product',
            'extra_fields'        => $data['extra_fields'] ?? null,
            'status'              => 'active',
            'is_enabled'          => true,
            'checkout_visible'    => true,
        ]);

        // Initialise stock for the current location so the item appears in stock queries.
        if ($locationId && ($data['stock_quantity'] ?? 0) > 0) {
            ItemLocationStock::create([
                'tenant_id'   => $tenant->id,
                'item_id'     => $item->id,
                'location_id' => $locationId,
                'quantity'    => $data['stock_quantity'],
            ]);
        }

        return response()->json(['ok' => true, 'item' => $item->fresh()], 201);
    }

    /**
     * PUT /api/v1/items/{item} — update an item from the POS app.
     */
    public function update(Request $request, Item $item): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('api_tenant');

        if ($item->tenant_id !== $tenant->id) {
            return response()->json(['ok' => false, 'message' => 'Article introuvable.'], 404);
        }

        $data = $request->validate([
            'title'               => ['sometimes', 'string', 'max:255'],
            'sale_price'          => ['sometimes', 'numeric', 'min:0'],
            'purchase_price'      => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'stock_quantity'      => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'min_stock_threshold' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'isbn'                => ['sometimes', 'nullable', 'string', 'max:30'],
            'barcode'             => ['sometimes', 'nullable', 'string', 'max:100'],
            'sku'                 => ['sometimes', 'nullable', 'string', 'max:100'],
            'author'              => ['sometimes', 'nullable', 'string', 'max:255'],
            'type'                => ['sometimes', 'nullable', 'string', 'in:product,service,book'],
            'extra_fields'        => ['sometimes', 'nullable', 'array'],
        ]);

        $item->update($data);

        return response()->json(['ok' => true, 'item' => $item->fresh()]);
    }

    /** GET /api/v1/items/{item} — single item detail with stock at location. */
    #[OA\Get(
        path: '/api/v1/items/{item}',
        operationId: 'itemShow',
        summary: 'Get a single item detail with stock at current location',
        security: [['bearerAuth' => []]],
        tags: ['Items'],
        parameters: [
            new OA\Parameter(name: 'X-Tenant-Slug', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Location-Id', in: 'header', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'item', in: 'path', required: true, description: 'Item ID', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Item detail',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'ok', type: 'boolean', example: true),
                    new OA\Property(property: 'item', type: 'object'),
                ])
            ),
            new OA\Response(response: 404, description: 'Item not found'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
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
