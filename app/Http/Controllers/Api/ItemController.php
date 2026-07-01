<?php

namespace App\Http\Controllers\Api;

use App\Enums\ItemStatus;
use App\Enums\ItemType;
use App\Http\Controllers\Controller;
use App\Support\ItemTypes;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Item;
use App\Models\ItemLocationStock;
use App\Models\Tax;
use App\Models\Tenant;
use App\Models\Unit;
use App\Services\Inventory\InventoryMovementType;
use App\Services\Inventory\InventoryService;
use App\Services\Inventory\MovementDTO;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

/**
 * Item lookup endpoints for the POS scanner and search.
 *
 * Intended for real-time lookup during a sale, NOT for bulk sync
 * (use GET /api/v1/sync/items for that).
 */
class ItemController extends Controller
{
    public function __construct(private readonly InventoryService $inventory) {}

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
            ->where('status', ItemStatus::Active->value)
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
            'local_id'            => ['required', 'uuid', 'max:100'],
            'title'               => ['required', 'string', 'max:255'],
            'sale_price'          => ['required', 'numeric', 'min:0'],
            'purchase_price'      => ['nullable', 'numeric', 'min:0'],
            'stock_quantity'      => ['nullable', 'integer', 'min:0'],
            'min_stock_threshold' => ['nullable', 'integer', 'min:0'],
            'isbn'                => ['nullable', 'string', 'max:30'],
            'barcode'             => ['nullable', 'string', 'max:100'],
            'sku'                 => ['nullable', 'string', 'max:100'],
            'author'              => ['nullable', 'string', 'max:255'],
            'category_id'         => ['nullable', 'integer', Rule::exists('categories', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id)->whereNull('deleted_at'))],
            'brand_id'            => ['nullable', 'integer', Rule::exists('brands', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id)->whereNull('deleted_at'))],
            'unit_id'             => ['required', 'integer', Rule::exists('units', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id)->where('is_active', true)->whereNull('deleted_at'))],
            'tax_id'              => ['required', 'integer', Rule::exists('taxes', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id)->where('is_active', true)->whereNull('deleted_at'))],
            'type'                => ['required', 'string', Rule::in(array_column(ItemType::cases(), 'value'))],
            'item_code'           => ['nullable', 'string', 'max:120', Rule::unique('items', 'item_code')->where(fn ($query) => $query->where('tenant_id', $tenant->id))],
            'item_group'          => ['nullable', Rule::in(['Single', 'Variants', 'Group', 'Pack'])],
            'extra_fields'        => ['nullable', 'array'],
        ]);

        // Idempotency: if a local_id is provided, return the existing item if it was
        // already created (handles retries after a lost network response).
        if (!empty($data['local_id'])) {
            $existing = Item::where('tenant_id', $tenant->id)
                ->where('external_id', $data['local_id'])
                ->first();
            if ($existing) {
                return response()->json(['ok' => true, 'already_existed' => true, 'item' => $existing->fresh()->load('tax')], 200);
            }
        }

        try {
            $item = DB::transaction(function () use ($tenant, $locationId, $data, $request): Item {
                $initialStock = $data['type'] === ItemType::Service->value ? 0 : (int) ($data['stock_quantity'] ?? 0);
                $item = Item::create([
                    'tenant_id'           => $tenant->id,
                    'external_id'         => $data['local_id'],
                    'title'               => $data['title'],
                    'sale_price'          => $data['sale_price'],
                    'purchase_price'      => $data['purchase_price'] ?? 0,
                    'stock_quantity'      => $initialStock,
                    'min_stock_threshold' => $data['min_stock_threshold'] ?? 3,
                    'isbn'                => $data['isbn'] ?? null,
                    'barcode'             => $data['barcode'] ?? null,
                    'sku'                 => $data['sku'] ?? null,
                    'author'              => $data['author'] ?? null,
                    'category_id'         => $data['category_id'] ?? null,
                    'brand_id'            => $data['brand_id'] ?? null,
                    'unit_id'             => $data['unit_id'],
                    'tax_id'              => $data['tax_id'],
                    'type'                => $data['type'],
                    'item_code'           => $data['item_code'] ?? null,
                    'item_group'          => $data['item_group'] ?? 'Single',
                    'extra_fields'        => $data['extra_fields'] ?? null,
                    'status'              => ItemStatus::fromTypeAndStock($data['type'], $initialStock)->value,
                    'is_enabled'          => true,
                    'checkout_visible'    => true,
                    'online_store_visible'=> true,
                ]);

                if (! $item->item_code) {
                    $item->update(['item_code' => $this->generatedItemCode($item)]);
                }

                // Initialise stock for the current location so the item appears in stock queries.
                if ($locationId && $data['type'] !== ItemType::Service->value && $initialStock > 0) {
                    $this->inventory->move(new MovementDTO(
                        tenantId: $tenant->id,
                        itemId: $item->id,
                        variantId: null,
                        locationId: $locationId,
                        type: InventoryMovementType::OPENING_STOCK,
                        quantityChanged: $initialStock,
                        userId: $request->user()?->id,
                        referenceType: Item::class,
                        referenceId: $item->id,
                        referenceNumber: $item->item_code,
                        note: 'Stock initial article mobile '.$item->item_code,
                        idempotencyKey: 'api-item-opening-'.$item->id,
                        unitCost: (float) ($data['purchase_price'] ?? 0) ?: null,
                    ));
                } elseif ($locationId && $data['type'] !== ItemType::Service->value) {
                    ItemLocationStock::create([
                        'tenant_id'   => $tenant->id,
                        'item_id'     => $item->id,
                        'location_id' => $locationId,
                        'quantity'    => 0,
                    ]);
                }

                return $item;
            });
        } catch (QueryException $exception) {
            $existing = Item::where('tenant_id', $tenant->id)
                ->where('external_id', $data['local_id'])
                ->first();
            if (! $existing) {
                throw $exception;
            }

            return response()->json(['ok' => true, 'already_existed' => true, 'item' => $existing->load('tax')], 200);
        }

        return response()->json(['ok' => true, 'already_existed' => false, 'item' => $item->fresh()->load('tax')], 201);
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
            'stock_quantity'      => ['prohibited'],
            'min_stock_threshold' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'isbn'                => ['sometimes', 'nullable', 'string', 'max:30'],
            'barcode'             => ['sometimes', 'nullable', 'string', 'max:100'],
            'sku'                 => ['sometimes', 'nullable', 'string', 'max:100'],
            'author'              => ['sometimes', 'nullable', 'string', 'max:255'],
            'category_id'         => ['sometimes', 'nullable', 'integer', Rule::exists('categories', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id)->whereNull('deleted_at'))],
            'brand_id'            => ['sometimes', 'nullable', 'integer', Rule::exists('brands', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id)->whereNull('deleted_at'))],
            'unit_id'             => ['sometimes', 'required', 'integer', Rule::exists('units', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id)->where('is_active', true)->whereNull('deleted_at'))],
            'tax_id'              => ['sometimes', 'required', 'integer', Rule::exists('taxes', 'id')->where(fn ($query) => $query->where('tenant_id', $tenant->id)->where('is_active', true)->whereNull('deleted_at'))],
            'type'                => ['sometimes', 'required', 'string', Rule::in(array_column(ItemType::cases(), 'value'))],
            'item_code'           => ['sometimes', 'required', 'string', 'max:120', Rule::unique('items', 'item_code')->where(fn ($query) => $query->where('tenant_id', $tenant->id))->ignore($item->id)],
            'item_group'          => ['sometimes', Rule::in(['Single', 'Variants', 'Group', 'Pack'])],
            'extra_fields'        => ['sometimes', 'nullable', 'array'],
        ]);

        $nextType = $data['type'] ?? $item->type;
        if ($nextType === ItemType::Service->value && $item->type !== ItemType::Service->value && $item->totalStockQuantity() > 0) {
            throw ValidationException::withMessages([
                'type' => 'Un article avec du stock ne peut pas devenir un service. Ajustez d’abord son stock à zéro.',
            ]);
        }

        if (array_key_exists('type', $data)) {
            $data['status'] = ItemStatus::fromTypeAndStock($nextType, (int) $item->stock_quantity)->value;
            if ($nextType === ItemType::Service->value) {
                $data['stock_quantity'] = 0;
            }
        }

        $item->update($data);

        return response()->json(['ok' => true, 'item' => $item->fresh()->load('tax')]);
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

    /**
     * POST /api/v1/items/{item}/images — upload or replace the item's image.
     */
    /**
     * GET /api/v1/catalog/item-types
     *
     * Returns the available item types and activity label for this tenant.
     * Used by the mobile app to show the correct type picker on Add Item / Add Service.
     */
    public function itemTypes(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant   = $request->attributes->get('api_tenant');
        $activity = (string) data_get($tenant->settings, 'store.business_activity', ItemTypes::defaultActivity());

        $rawTypes = ItemTypes::physicalTypes($activity);
        $physical = array_values(array_map(fn ($key, $type) => [
            'value'        => $key,
            'label'        => $type['label'],
            'hint'         => $type['hint'],
            'tracks_stock' => true,
        ], array_keys($rawTypes), $rawTypes));

        return response()->json([
            'ok'       => true,
            'activity' => [
                'key'   => $activity,
                'label' => ItemTypes::activityLabel($activity),
            ],
            'physical_types' => $physical,
            'service_type' => [
                'value'        => 'service',
                'label'        => 'Service',
                'hint'         => 'Prestation sans stock',
                'tracks_stock' => false,
            ],
        ]);
    }

    public function uploadImage(Request $request, Item $item): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('api_tenant');

        if ($item->tenant_id !== $tenant->id) {
            return response()->json(['ok' => false, 'message' => 'Article introuvable.'], 404);
        }

        $request->validate([
            'image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);

        $path = $request->file('image')->store('catalogue/items', 'public');

        $item->update(['images' => [$path]]);

        return response()->json(['ok' => true, 'image_url' => $path]);
    }

    private function generatedItemCode(Item $item): string
    {
        $base = 'IT'.$item->created_at->format('ym').str_pad((string) $item->id, 4, '0', STR_PAD_LEFT);
        $code = $base;
        $suffix = 1;
        while (
            Item::where('tenant_id', $item->tenant_id)
                ->where('item_code', $code)
                ->where('id', '!=', $item->id)
                ->exists()
        ) {
            $code = $base.'-'.$suffix++;
        }
        return $code;
    }
}
