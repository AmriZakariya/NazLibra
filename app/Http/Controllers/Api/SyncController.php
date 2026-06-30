<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\SaleResource;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Contact;
use App\Models\ContactTransaction;
use App\Models\Item;
use App\Models\ItemLocationStock;
use App\Models\OnlineOrder;
use App\Models\Purchase;
use App\Models\SaleInvoice;
use App\Models\Tax;
use App\Models\Tenant;
use App\Models\Unit;
use App\Support\UtcDateTime;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

/**
 * Delta-sync endpoints for the offline-first mobile app.
 *
 * SYNC CURSOR STRATEGY
 * --------------------
 * Each endpoint returns one frozen `sync_at` watermark. Paginated endpoints
 * also return an opaque `next_cursor` that advances by (updated_at, id)
 * inside that frozen window. The client promotes sync_at only after the final
 * page. The lower bound is inclusive, so boundary duplicates are expected and
 * safe because clients upsert by id.
 *
 * LARGE CATALOGS (10k+ items)
 * ----------------------------
 * Items are paginated.  Metadata (categories, brands, units, taxes) are
 * returned in full — they rarely exceed a few hundred rows.
 * Call GET /sync/items for paginated items, GET /sync/meta for metadata,
 * GET /sync/stock for stock snapshot, GET /sync/contacts for customers.
 */
class SyncController extends Controller
{
    private const DEFAULT_ITEMS_PER_PAGE   = 200;
    private const MAX_ITEMS_PER_PAGE       = 500;
    private const DEFAULT_CONTACT_PER_PAGE = 200;
    private const MAX_CONTACT_PER_PAGE     = 500;

    // ── Items (paginated) ────────────────────────────────────────────────────

    /**
     * GET /api/v1/sync/items
     *
     * Paginated item delta.  On first install, omit `since` to download the
     * full catalog page by page.  On subsequent calls, pass `since` from the
     * previous response's `sync_at`.
     *
     * Query params:
     *   since    ISO-8601 UTC timestamp (optional, inclusive >=)
     *   page     integer (default 1)
     *   per_page integer (default 200, max 500)
     */
    #[OA\Get(
        path: '/api/v1/sync/items',
        operationId: 'syncItems',
        summary: 'Paginated item delta sync',
        security: [['bearerAuth' => []]],
        tags: ['Sync'],
        parameters: [
            new OA\Parameter(name: 'X-Tenant-Slug', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Location-Id', in: 'header', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'since', in: 'query', required: false, description: 'ISO-8601 UTC cursor (inclusive >=)', schema: new OA\Schema(type: 'string', format: 'date-time')),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 200, maximum: 500)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated items',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'ok', type: 'boolean', example: true),
                    new OA\Property(property: 'sync_at', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'page', type: 'integer'),
                    new OA\Property(property: 'per_page', type: 'integer'),
                    new OA\Property(property: 'total', type: 'integer'),
                    new OA\Property(property: 'has_more', type: 'boolean'),
                    new OA\Property(property: 'items', type: 'array', items: new OA\Items(type: 'object')),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function items(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant  = $request->attributes->get('api_tenant');
        $query = Item::withTrashed()
            ->where('tenant_id', $tenant->id)
            ->with([
                'category:id,name,slug',
                'brand:id,name',
                'unit:id,name',
                'tax:id,name,rate',
            ]);

        $page = $this->syncPage($request, $query, 'items', $tenant->id, self::DEFAULT_ITEMS_PER_PAGE, self::MAX_ITEMS_PER_PAGE, [
            'id', 'external_id', 'category_id', 'brand_id', 'unit_id', 'tax_id',
            'type', 'status', 'is_enabled', 'checkout_visible', 'online_store_visible',
            'item_code', 'item_group', 'nb_item', 'title', 'isbn', 'barcode', 'sku', 'custom_barcode1', 'author',
            'sale_price', 'purchase_price', 'min_stock_threshold',
            'stock_quantity', 'images', 'extra_fields', 'description', 'tags',
            'updated_at', 'created_at', 'deleted_at',
        ]);

        return response()->json([
            'ok'       => true,
            ...$page['meta'],
            'items'    => $page['rows'],
        ]);
    }

    // ── Metadata (categories, brands, units, taxes) ──────────────────────────

    /**
     * GET /api/v1/sync/meta
     *
     * Returns all categories, brands, units and taxes for the tenant.
     * These collections are small (rarely more than a few hundred rows)
     * so they are not paginated.  Pass `since` for delta.
     */
    #[OA\Get(
        path: '/api/v1/sync/meta',
        operationId: 'syncMeta',
        summary: 'Sync categories, brands, units and taxes',
        security: [['bearerAuth' => []]],
        tags: ['Sync'],
        parameters: [
            new OA\Parameter(name: 'X-Tenant-Slug', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Location-Id', in: 'header', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'since', in: 'query', required: false, description: 'ISO-8601 UTC cursor', schema: new OA\Schema(type: 'string', format: 'date-time')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Metadata collections',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'ok', type: 'boolean', example: true),
                    new OA\Property(property: 'sync_at', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'categories', type: 'array', items: new OA\Items(type: 'object')),
                    new OA\Property(property: 'brands', type: 'array', items: new OA\Items(type: 'object')),
                    new OA\Property(property: 'units', type: 'array', items: new OA\Items(type: 'object')),
                    new OA\Property(property: 'taxes', type: 'array', items: new OA\Items(type: 'object')),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function meta(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('api_tenant');
        $since  = $this->parseSince($request);
        $syncAt = $this->newSnapshotAt();

        $categories = Category::withTrashed()->where('tenant_id', $tenant->id)
            ->when($since, fn ($q) => $q->where('updated_at', '>=', $since))
            ->where('updated_at', '<=', $syncAt)
            ->orderBy('updated_at')->orderBy('id')
            ->get(['id', 'parent_id', 'name', 'slug', 'icon', 'color', 'updated_at', 'deleted_at'])
            ->toArray();

        $brands = Brand::withTrashed()->where('tenant_id', $tenant->id)
            ->when($since, fn ($q) => $q->where('updated_at', '>=', $since))
            ->where('updated_at', '<=', $syncAt)
            ->orderBy('updated_at')->orderBy('id')
            ->get(['id', 'name', 'is_active', 'updated_at', 'deleted_at'])
            ->toArray();

        $units = Unit::withTrashed()->where('tenant_id', $tenant->id)
            ->when($since, fn ($q) => $q->where('updated_at', '>=', $since))
            ->where('updated_at', '<=', $syncAt)
            ->orderBy('updated_at')->orderBy('id')
            ->get(['id', 'name', 'updated_at', 'deleted_at'])
            ->toArray();

        $taxes = Tax::withTrashed()->where('tenant_id', $tenant->id)
            ->when($since, fn ($q) => $q->where('updated_at', '>=', $since))
            ->where('updated_at', '<=', $syncAt)
            ->orderBy('updated_at')->orderBy('id')
            ->get(['id', 'name', 'rate', 'is_active', 'updated_at', 'deleted_at'])
            ->toArray();

        return response()->json([
            'ok'               => true,
            'sync_at'          => $this->formatCursorTime($syncAt),
            'is_full_snapshot' => $since === null,
            'has_more'         => false,
            'next_cursor'      => null,
            'categories'       => $categories,
            'brands'           => $brands,
            'units'            => $units,
            'taxes'            => $taxes,
        ]);
    }

    // ── Stock snapshot ────────────────────────────────────────────────────────

    /**
     * GET /api/v1/sync/stock?location_id=<id>
     *
     * Full stock snapshot at a location. Call at session start and after
     * each offline-sync batch to update local available quantities.
     * Not delta-paginated — always returns the complete current state for
     * the location so the device can fully refresh its local cache.
     */
    #[OA\Get(
        path: '/api/v1/sync/stock',
        operationId: 'syncStock',
        summary: 'Full stock snapshot at the current location',
        security: [['bearerAuth' => []]],
        tags: ['Sync'],
        parameters: [
            new OA\Parameter(name: 'X-Tenant-Slug', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Location-Id', in: 'header', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'since', in: 'query', required: false, description: 'ISO-8601 UTC cursor', schema: new OA\Schema(type: 'string', format: 'date-time')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Stock snapshot',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'ok', type: 'boolean', example: true),
                    new OA\Property(property: 'sync_at', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'location_id', type: 'integer'),
                    new OA\Property(property: 'stock', type: 'array', items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'item_id', type: 'integer'),
                            new OA\Property(property: 'variant_id', type: 'integer', nullable: true),
                            new OA\Property(property: 'qty', type: 'integer'),
                            new OA\Property(property: 'reserved', type: 'integer'),
                            new OA\Property(property: 'available', type: 'integer'),
                            new OA\Property(property: 'avg_cost', type: 'number', format: 'float'),
                            new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
                        ]
                    )),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'No location configured'),
        ]
    )]
    public function stock(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant     = $request->attributes->get('api_tenant');
        $locationId = $request->attributes->get('api_location_id');
        $since      = $this->parseSince($request);
        $syncAt     = $this->newSnapshotAt();

        if (! $locationId) {
            return response()->json(['ok' => false, 'message' => 'Aucun emplacement.'], 422);
        }

        $stocks = ItemLocationStock::withTrashed()
            ->where('tenant_id', $tenant->id)
            ->where('location_id', $locationId)
            ->when($since, fn ($q) => $q->where('updated_at', '>=', $since))
            ->where('updated_at', '<=', $syncAt)
            ->orderBy('item_id')
            ->orderByRaw('CASE WHEN variant_id IS NULL THEN 0 ELSE 1 END')
            ->orderBy('variant_id')
            ->get([
                'item_id', 'variant_id',
                'quantity', 'reserved_quantity',
                'average_cost', 'last_purchase_cost',
                'updated_at', 'deleted_at',
            ])
            ->map(fn ($s) => [
                'item_id'    => $s->item_id,
                'variant_id' => $s->variant_id,
                'qty'        => (int) $s->quantity,
                'reserved'   => (int) $s->reserved_quantity,
                'available'  => max(0, (int) $s->quantity - (int) $s->reserved_quantity),
                'avg_cost'   => (float) $s->average_cost,
                'updated_at' => $s->updated_at?->toISOString(),
                'deleted_at' => $s->deleted_at?->toISOString(),
            ]);

        return response()->json([
            'ok'          => true,
            'sync_at'     => $this->formatCursorTime($syncAt),
            'location_id' => $locationId,
            'is_full_snapshot' => $since === null,
            'has_more'    => false,
            'next_cursor' => null,
            'stock'       => $stocks,
        ]);
    }

    // ── Contacts (paginated) ──────────────────────────────────────────────────

    /**
     * GET /api/v1/sync/contacts
     *
     * Paginated customer delta.
     */
    #[OA\Get(
        path: '/api/v1/sync/contacts',
        operationId: 'syncContacts',
        summary: 'Paginated contact (customer) delta sync',
        security: [['bearerAuth' => []]],
        tags: ['Sync'],
        parameters: [
            new OA\Parameter(name: 'X-Tenant-Slug', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Location-Id', in: 'header', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'since', in: 'query', required: false, description: 'ISO-8601 UTC cursor', schema: new OA\Schema(type: 'string', format: 'date-time')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated contacts',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'ok', type: 'boolean', example: true),
                    new OA\Property(property: 'sync_at', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'page', type: 'integer'),
                    new OA\Property(property: 'per_page', type: 'integer'),
                    new OA\Property(property: 'total', type: 'integer'),
                    new OA\Property(property: 'has_more', type: 'boolean'),
                    new OA\Property(property: 'contacts', type: 'array', items: new OA\Items(type: 'object')),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function contacts(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant  = $request->attributes->get('api_tenant');
        // Allow syncing a specific kind; default syncs both client and supplier
        $kind = $request->query('kind');
        if ($kind !== null && ! in_array($kind, ['client', 'supplier'], true)) {
            throw ValidationException::withMessages(['kind' => 'kind doit être client ou supplier.']);
        }

        $paginated = Contact::withTrashed()
            ->where('tenant_id', $tenant->id)
            ->when($kind, fn ($q) => $q->where('kind', $kind));

        $page = $this->syncPage($request, $paginated, 'contacts:'.($kind ?? 'all'), $tenant->id, self::DEFAULT_CONTACT_PER_PAGE, self::MAX_CONTACT_PER_PAGE, [
                'id', 'kind', 'code', 'status', 'name', 'email', 'phone', 'address',
                'advance_balance', 'outstanding_balance', 'credit_limit', 'loyalty_points',
                'updated_at', 'created_at', 'deleted_at',
            ]);

        return response()->json([
            'ok'       => true,
            ...$page['meta'],
            'contacts' => $page['rows'],
        ]);
    }

    // ── Sales delta ───────────────────────────────────────────────────────────

    /**
     * GET /api/v1/sync/sales
     *
     * Paginated sale delta for the device to rebuild its local history.
     * Returns every status plus deletion tombstones for the current location.
     */
    #[OA\Get(
        path: '/api/v1/sync/sales',
        operationId: 'syncSales',
        summary: 'Paginated location-scoped sales delta sync',
        security: [['bearerAuth' => []]],
        tags: ['Sync'],
        parameters: [
            new OA\Parameter(name: 'X-Tenant-Slug', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Location-Id', in: 'header', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'since', in: 'query', required: false, description: 'ISO-8601 UTC cursor', schema: new OA\Schema(type: 'string', format: 'date-time')),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 100, maximum: 200)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated sales',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'ok', type: 'boolean', example: true),
                    new OA\Property(property: 'sync_at', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'page', type: 'integer'),
                    new OA\Property(property: 'per_page', type: 'integer'),
                    new OA\Property(property: 'total', type: 'integer'),
                    new OA\Property(property: 'has_more', type: 'boolean'),
                    new OA\Property(property: 'sales', type: 'array', items: new OA\Items(type: 'object')),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function sales(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant  = $request->attributes->get('api_tenant');
        $query = \App\Models\Sale::withTrashed()
            ->where('tenant_id', $tenant->id)
            ->where('location_id', $request->attributes->get('api_location_id'))
            ->with([
                'items:id,sale_id,item_id,name,quantity,unit_price,total_price,unit_cost,total_cost',
                'contact:id,name,phone',
                'user:id,name',
                'virtualDevice:id,name',
                'location:id,name',
            ]);

        $page = $this->syncPage($request, $query, 'sales:location-'.$request->attributes->get('api_location_id'), $tenant->id, 100, 200, [
                'id', 'location_id', 'contact_id', 'user_id', 'virtual_device_id', 'actor_name_snapshot', 'terminal_name_snapshot', 'number', 'status', 'payment_method',
                'subtotal_amount', 'discount_amount', 'total_amount',
                'sold_at', 'updated_at', 'deleted_at',
            ]);

        return response()->json([
            'ok'       => true,
            ...$page['meta'],
            'sales'    => SaleResource::collection($page['rows']),
        ]);
    }

    // ── Settings ──────────────────────────────────────────────────────────────

    /**
     * GET /api/v1/sync/settings
     *
     * Returns tenant-level settings the mobile app needs to stay in sync with
     * the back-office: timezone, currency, locale, POS behaviour flags, and the
     * current location name. Call on first launch and on every delta sync so the
     * app always reflects admin changes without requiring a re-login.
     */
    #[OA\Get(
        path: '/api/v1/sync/settings',
        operationId: 'syncSettings',
        summary: 'Tenant settings (timezone, currency, locale, POS flags)',
        security: [['bearerAuth' => []]],
        tags: ['Sync'],
        parameters: [
            new OA\Parameter(name: 'X-Tenant-Slug', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Location-Id', in: 'header', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Tenant settings',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'ok', type: 'boolean'),
                    new OA\Property(property: 'sync_at', type: 'string', format: 'date-time'),
                    new OA\Property(property: 'timezone', type: 'string', example: 'Africa/Casablanca'),
                    new OA\Property(property: 'currency', type: 'string', example: 'MAD'),
                    new OA\Property(property: 'locale', type: 'string', example: 'fr'),
                    new OA\Property(property: 'tenant_name', type: 'string'),
                    new OA\Property(property: 'location_name', type: 'string', nullable: true),
                    new OA\Property(property: 'allow_oversell', type: 'boolean'),
                    new OA\Property(property: 'receipt_footer', type: 'string', nullable: true),
                    new OA\Property(property: 'receipt_header', type: 'string', nullable: true),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function settings(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant     = $request->attributes->get('api_tenant');
        $locationId = $request->attributes->get('api_location_id');

        $location = $locationId
            ? \App\Models\Location::where('tenant_id', $tenant->id)->find($locationId)
            : null;

        $syncAt = $this->newSnapshotAt();

        return response()->json([
            'ok'                      => true,
            'sync_at'                 => $this->formatCursorTime($syncAt),
            'has_more'                => false,
            'next_cursor'             => null,
            'tenant_id'               => $tenant->id,
            'location_id'             => $location?->id,
            'timezone'                => $tenant->timezone ?? 'Africa/Casablanca',
            'currency'                => $tenant->currency ?? 'MAD',
            'locale'                  => $tenant->locale   ?? 'fr',
            'tenant_name'             => $tenant->name,
            'tenant_phone'            => $tenant->phone,
            'tenant_address'          => $tenant->address,
            'location_name'           => $location?->name,
            'allow_oversell'          => (bool) data_get($tenant->settings, 'pos.allow_oversell', false),
            'receipt_header'          => data_get($tenant->settings, 'receipt.header'),
            'receipt_footer'          => data_get($tenant->settings, 'receipt.footer'),
            'features_virtual_devices'=> (bool) data_get($tenant->settings, 'features.virtual_devices', true),
            'business_mode'           => $tenant->business_mode ?? 'retail',
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Parse the ?since= parameter to a UTC datetime string.
     * Returns null if absent or invalid.
     */
    private function parseSince(Request $request): ?Carbon
    {
        $raw = $request->query('since');
        if (! $raw) {
            return null;
        }
        try {
            return UtcDateTime::parse((string) $raw);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['since' => 'Le curseur since est invalide.']);
        }
    }

    // ── Invoices ──────────────────────────────────────────────────────────────

    /**
     * GET /api/v1/sync/invoices
     *
     * Paginated delta of sale invoices updated since the cursor.
     */
    public function invoices(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant  = $request->attributes->get('api_tenant');
        $locationId = $request->attributes->get('api_location_id');

        $query = SaleInvoice::withTrashed()
            ->with(['sale:id,number,sold_at,status,total_amount,contact_id', 'contact:id,name,phone,email'])
            ->where('tenant_id', $tenant->id)
            ->where(function ($q) use ($locationId) {
                // Invoices without a linked sale (created directly on web) have no
                // location; include them tenant-wide. Invoices with a sale are
                // scoped to the sale's location.
                $q->whereNull('sale_id')
                  ->orWhereHas('sale', fn ($s) => $s->withTrashed()->where('location_id', $locationId));
            });

        $page = $this->syncPage($request, $query, 'invoices:location-'.$request->attributes->get('api_location_id'), $tenant->id, 100, 200, ['*']);

        return response()->json([
            'ok'       => true,
            ...$page['meta'],
            'invoices' => $page['rows'],
        ]);
    }

    // ── Purchases ─────────────────────────────────────────────────────────────

    /**
     * GET /api/v1/sync/purchases
     *
     * Paginated delta of purchase orders updated since the cursor.
     * Items are embedded in each purchase to avoid a second round-trip.
     */
    public function purchases(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('api_tenant');

        $query = Purchase::withTrashed()
            ->with([
                'supplier:id,name,phone',
                'items:id,purchase_id,item_id,quantity_ordered,quantity_received,unit_cost',
                'items.item:id,title,barcode,sku',
            ])
            ->where('tenant_id', $tenant->id);

        $page = $this->syncPage($request, $query, 'purchases', $tenant->id, 50, 100, ['*']);

        $rows = collect($page['rows'])->map(function (Purchase $p) {
            return [
                'id'             => $p->id,
                'tenant_id'      => $p->tenant_id,
                'supplier_id'    => $p->supplier_id,
                'supplier_name'  => $p->supplier?->name,
                'supplier_phone' => $p->supplier?->phone,
                'user_id'        => $p->user_id,
                'number'         => $p->number,
                'status'         => $p->status,
                'total_amount'   => (float) $p->total_amount,
                'ordered_at'     => $p->ordered_at?->toDateString(),
                'expected_at'    => $p->expected_at?->toDateString(),
                'received_at'    => $p->received_at?->toDateString(),
                'note'           => $p->metadata['note'] ?? null,
                'updated_at'     => $p->updated_at->toISOString(),
                'deleted_at'     => $p->deleted_at?->toISOString(),
                'items'          => $p->items->map(fn ($item) => [
                    'id'                => $item->id,
                    'item_id'           => $item->item_id,
                    'item_title'        => $item->item?->title,
                    'item_barcode'      => $item->item?->barcode,
                    'quantity_ordered'  => (float) $item->quantity_ordered,
                    'quantity_received' => (float) $item->quantity_received,
                    'unit_cost'         => (float) $item->unit_cost,
                ])->values()->all(),
            ];
        });

        return response()->json([
            'ok'        => true,
            ...$page['meta'],
            'purchases' => $rows->values(),
        ]);
    }

    // ── Online orders ─────────────────────────────────────────────────────────

    /**
     * GET /api/v1/sync/online-orders
     *
     * Paginated delta of online orders updated since the cursor.
     * Items are embedded in each order to avoid a second round-trip.
     */
    public function onlineOrders(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('api_tenant');

        $query = OnlineOrder::withTrashed()
            ->with([
                'items:id,online_order_id,item_id,name,code,quantity,unit_price,discount_amount,total_amount,note,display_order',
            ])
            ->where('tenant_id', $tenant->id);

        $page = $this->syncPage($request, $query, 'online_orders', $tenant->id, 50, 100, ['*']);

        $rows = collect($page['rows'])->map(function (OnlineOrder $o) {
            return [
                'id'               => $o->id,
                'tenant_id'        => $o->tenant_id,
                'contact_id'       => $o->contact_id,
                'user_id'          => $o->user_id,
                'converted_sale_id'=> $o->converted_sale_id,
                'number'           => $o->number,
                'channel'          => $o->channel,
                'status'           => $o->status,
                'payment_status'   => $o->payment_status,
                'customer_name'    => $o->customer_name,
                'customer_phone'   => $o->customer_phone,
                'customer_email'   => $o->customer_email,
                'delivery_address' => $o->delivery_address,
                'ordered_at'       => $o->ordered_at?->toISOString(),
                'expected_at'      => $o->expected_at?->toDateString(),
                'subtotal_amount'  => (float) $o->subtotal_amount,
                'discount_amount'  => (float) $o->discount_amount,
                'deposit_amount'   => (float) $o->deposit_amount,
                'total_amount'     => (float) $o->total_amount,
                'customer_note'    => $o->customer_note,
                'internal_note'    => $o->internal_note,
                'updated_at'       => $o->updated_at->toISOString(),
                'deleted_at'       => $o->deleted_at?->toISOString(),
                'items'            => $o->items->sortBy('display_order')->values()->map(fn ($item) => [
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
                ])->all(),
            ];
        });

        return response()->json([
            'ok'            => true,
            ...$page['meta'],
            'online_orders' => $rows->values(),
        ]);
    }

    // ── Printer config ────────────────────────────────────────────────────────

    /**
     * GET /api/v1/sync/printers
     *
     * Returns the full printer configuration for the requesting virtual device.
     * Reads X-Virtual-Device-Id header; null means tenant-wide (no device scope).
     */
    public function printers(Request $request): JsonResponse
    {
        return (new \App\Http\Controllers\Api\PrinterController())->index($request);
    }

    /** GET /api/v1/sync/catalog — legacy alias for /sync/items, kept for backward compat. */
    #[OA\Get(
        path: '/api/v1/sync/catalog',
        operationId: 'syncCatalog',
        summary: 'Legacy alias for /sync/items (paginated item delta)',
        security: [['bearerAuth' => []]],
        tags: ['Sync'],
        parameters: [
            new OA\Parameter(name: 'X-Tenant-Slug', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Location-Id', in: 'header', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'since', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date-time')),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 200, maximum: 500)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated items (same as /sync/items)'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function catalog(Request $request): JsonResponse
    {
        return $this->items($request);
    }

    /**
     * Delta-sync contact transactions.
     * Returns all transactions (including soft-deleted) updated since cursor.
     */
    public function contactTransactions(Request $request): JsonResponse
    {
        $tenant  = $request->attributes->get('api_tenant');
        $query = ContactTransaction::withTrashed()
            ->where('tenant_id', $tenant->id);

        $page = $this->syncPage($request, $query, 'contact-transactions', $tenant->id, 200, 500, [
            'id', 'tenant_id', 'contact_id', 'type', 'amount',
            'note', 'recorded_at', 'updated_at', 'deleted_at',
        ]);

        return response()->json([
            'ok'           => true,
            ...$page['meta'],
            'transactions' => $page['rows'],
        ]);
    }

    private function syncPage(Request $request, Builder $baseQuery, string $scope, int $tenantId, int $defaultPerPage, int $maxPerPage, array $columns): array
    {
        $cursor = $request->query('cursor');
        $window = $cursor
            ? $this->decodeSyncCursor((string) $cursor, $scope, $tenantId)
            : [
                'since' => $this->parseSince($request),
                'sync_at' => $this->newSnapshotAt(),
                'after_updated_at' => null,
                'after_id' => null,
                'page' => 1,
                'per_page' => $this->validatedPerPage($request, $defaultPerPage, $maxPerPage),
                'total' => null,
            ];

        if (! $cursor && (int) $request->query('page', 1) !== 1) {
            throw ValidationException::withMessages(['cursor' => 'Utilisez next_cursor pour charger la page suivante.']);
        }

        $bounded = (clone $baseQuery)
            ->when($window['since'], fn (Builder $query) => $query->where('updated_at', '>=', $window['since']))
            ->where('updated_at', '<=', $window['sync_at']);

        $total = $window['total'] ?? (clone $bounded)->count();

        if ($window['after_updated_at'] !== null) {
            $bounded->where(function (Builder $query) use ($window): void {
                $query->where('updated_at', '>', $window['after_updated_at'])
                    ->orWhere(function (Builder $sameTimestamp) use ($window): void {
                        $sameTimestamp->where('updated_at', '=', $window['after_updated_at'])
                            ->where('id', '>', $window['after_id']);
                    });
            });
        }

        $rows = $bounded->orderBy('updated_at')->orderBy('id')
            ->limit($window['per_page'] + 1)
            ->get($columns);
        $hasMore = $rows->count() > $window['per_page'];
        $rows = $rows->take($window['per_page'])->values();
        $last = $rows->last();

        $nextCursor = $hasMore && $last
            ? $this->encodeSyncCursor([
                'scope' => $scope,
                'tenant_id' => $tenantId,
                'since' => $window['since'] ? $this->formatCursorTime($window['since']) : null,
                'sync_at' => $this->formatCursorTime($window['sync_at']),
                'after_updated_at' => $last->getRawOriginal('updated_at'),
                'after_id' => $last->id,
                'page' => $window['page'] + 1,
                'per_page' => $window['per_page'],
                'total' => $total,
            ])
            : null;

        return [
            'rows' => $rows,
            'meta' => [
                'sync_at' => $this->formatCursorTime($window['sync_at']),
                'is_full_snapshot' => $window['since'] === null,
                'page' => $window['page'],
                'per_page' => $window['per_page'],
                'total' => $total,
                'has_more' => $hasMore,
                'next_cursor' => $nextCursor,
            ],
        ];
    }

    private function validatedPerPage(Request $request, int $default, int $max): int
    {
        $value = filter_var($request->query('per_page', $default), FILTER_VALIDATE_INT);
        if ($value === false || $value < 1 || $value > $max) {
            throw ValidationException::withMessages(['per_page' => "per_page doit être compris entre 1 et {$max}."]);
        }

        return $value;
    }

    private function encodeSyncCursor(array $payload): string
    {
        return Crypt::encryptString(json_encode(['v' => 1, ...$payload], JSON_THROW_ON_ERROR));
    }

    private function decodeSyncCursor(string $cursor, string $scope, int $tenantId): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($cursor), true, flags: JSON_THROW_ON_ERROR);
            if (($payload['v'] ?? null) !== 1 || ($payload['scope'] ?? null) !== $scope || (int) ($payload['tenant_id'] ?? 0) !== $tenantId) {
                throw new \RuntimeException('Cursor scope mismatch.');
            }

            return [
                'since' => ! empty($payload['since']) ? UtcDateTime::parse($payload['since']) : null,
                'sync_at' => UtcDateTime::parse($payload['sync_at']),
                'after_updated_at' => $payload['after_updated_at'] ?? null,
                'after_id' => (int) ($payload['after_id'] ?? 0),
                'page' => max(1, (int) ($payload['page'] ?? 1)),
                'per_page' => max(1, (int) ($payload['per_page'] ?? 1)),
                'total' => isset($payload['total']) ? (int) $payload['total'] : null,
            ];
        } catch (\Throwable) {
            throw ValidationException::withMessages(['cursor' => 'Le curseur de synchronisation est invalide ou expiré.']);
        }
    }

    private function formatCursorTime(Carbon $time): string
    {
        return UtcDateTime::format($time);
    }

    private function newSnapshotAt(): Carbon
    {
        // Schema timestamps currently have second precision. Close the window
        // on the previous complete second. The next delta uses >=, so writes in
        // the open second are replayed later and can never fall behind a cursor.
        return now()->utc()->startOfSecond()->subSecond();
    }
}
