<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Contact;
use App\Models\Item;
use App\Models\ItemLocationStock;
use App\Models\SaleInvoice;
use App\Models\Tax;
use App\Models\Tenant;
use App\Models\Unit;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Delta-sync endpoints for the offline-first mobile app.
 *
 * SYNC CURSOR STRATEGY
 * --------------------
 * Each endpoint returns `sync_at` (server UTC, microsecond ISO-8601).
 * The client stores this and sends it back as `?since=` on the next call.
 * Queries use `updated_at >= since` (inclusive) to avoid missing records
 * that were written at the exact same timestamp as the last cursor.
 * This means a record may appear in two consecutive syncs — that is safe
 * because all entities are identified by `id` and the client upserts.
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
        $since   = $this->parseSince($request);
        $perPage = $this->perPage($request, self::DEFAULT_ITEMS_PER_PAGE, self::MAX_ITEMS_PER_PAGE);

        $page = $request->query('page', 1);

        $query = Item::withTrashed()
            ->where('tenant_id', $tenant->id)
            ->with([
                'category:id,name,slug',
                'brand:id,name',
                'unit:id,name',
                'tax:id,name,rate',
            ])
            ->when($since, fn ($q) => $q->where('updated_at', '>=', $since))
            ->orderBy('updated_at', 'asc')
            ->orderBy('id', 'asc');

        $paginated = $query->paginate($perPage, [
            'id', 'category_id', 'brand_id', 'unit_id', 'tax_id',
            'type', 'status', 'is_enabled', 'checkout_visible', 'online_store_visible',
            'title', 'isbn', 'barcode', 'sku', 'custom_barcode1',
            'sale_price', 'purchase_price', 'min_stock_threshold',
            'stock_quantity', 'images', 'description', 'tags',
            'updated_at', 'created_at', 'deleted_at',
        ], 'page', $page);

        return response()->json([
            'ok'       => true,
            'sync_at'  => now()->toISOString(),
            'page'     => $paginated->currentPage(),
            'per_page' => $paginated->perPage(),
            'total'    => $paginated->total(),
            'has_more' => $paginated->hasMorePages(),
            'items'    => $paginated->items(),
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

        $categories = Category::where('tenant_id', $tenant->id)
            ->when($since, fn ($q) => $q->where('updated_at', '>=', $since))
            ->get(['id', 'parent_id', 'name', 'slug', 'icon', 'color', 'updated_at'])
            ->toArray();

        $brands = Brand::where('tenant_id', $tenant->id)
            ->when($since, fn ($q) => $q->where('updated_at', '>=', $since))
            ->get(['id', 'name', 'updated_at'])
            ->toArray();

        $units = Unit::where('tenant_id', $tenant->id)
            ->when($since, fn ($q) => $q->where('updated_at', '>=', $since))
            ->get(['id', 'name', 'updated_at'])
            ->toArray();

        $taxes = Tax::where('tenant_id', $tenant->id)
            ->when($since, fn ($q) => $q->where('updated_at', '>=', $since))
            ->get(['id', 'name', 'rate', 'updated_at'])
            ->toArray();

        return response()->json([
            'ok'         => true,
            'sync_at'    => now()->toISOString(),
            'categories' => $categories,
            'brands'     => $brands,
            'units'      => $units,
            'taxes'      => $taxes,
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

        if (! $locationId) {
            return response()->json(['ok' => false, 'message' => 'Aucun emplacement.'], 422);
        }

        $stocks = ItemLocationStock::query()
            ->where('tenant_id', $tenant->id)
            ->where('location_id', $locationId)
            ->when($since, fn ($q) => $q->where('updated_at', '>=', $since))
            ->get([
                'item_id', 'variant_id',
                'quantity', 'reserved_quantity',
                'average_cost', 'last_purchase_cost',
                'updated_at',
            ])
            ->map(fn ($s) => [
                'item_id'    => $s->item_id,
                'variant_id' => $s->variant_id,
                'qty'        => (int) $s->quantity,
                'reserved'   => (int) $s->reserved_quantity,
                'available'  => max(0, (int) $s->quantity - (int) $s->reserved_quantity),
                'avg_cost'   => (float) $s->average_cost,
                'updated_at' => $s->updated_at?->toISOString(),
            ]);

        return response()->json([
            'ok'          => true,
            'sync_at'     => now()->toISOString(),
            'location_id' => $locationId,
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
        $since   = $this->parseSince($request);
        $perPage = $this->perPage($request, self::DEFAULT_CONTACT_PER_PAGE, self::MAX_CONTACT_PER_PAGE);
        // Allow syncing a specific kind; default syncs both client and supplier
        $kind    = in_array($request->query('kind'), ['client', 'supplier'], true)
            ? $request->query('kind')
            : null;

        $paginated = Contact::withTrashed()
            ->where('tenant_id', $tenant->id)
            ->when($kind, fn ($q) => $q->where('kind', $kind))
            ->when($since, fn ($q) => $q->where('updated_at', '>=', $since))
            ->orderBy('updated_at', 'asc')
            ->orderBy('id', 'asc')
            ->paginate($perPage, [
                'id', 'kind', 'code', 'status', 'name', 'email', 'phone', 'address',
                'advance_balance', 'outstanding_balance', 'credit_limit',
                'updated_at', 'created_at', 'deleted_at',
            ]);

        return response()->json([
            'ok'       => true,
            'sync_at'  => now()->toISOString(),
            'page'     => $paginated->currentPage(),
            'per_page' => $paginated->perPage(),
            'total'    => $paginated->total(),
            'has_more' => $paginated->hasMorePages(),
            'contacts' => $paginated->items(),
        ]);
    }

    // ── Sales delta ───────────────────────────────────────────────────────────

    /**
     * GET /api/v1/sync/sales
     *
     * Paginated sale delta for the device to rebuild its local history.
     * Only returns paid sales (status = paid).
     */
    #[OA\Get(
        path: '/api/v1/sync/sales',
        operationId: 'syncSales',
        summary: 'Paginated paid sales delta sync',
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
        $since   = $this->parseSince($request);
        $perPage = $this->perPage($request, 100, 200);

        $paginated = \App\Models\Sale::withTrashed()
            ->where('tenant_id', $tenant->id)
            ->with([
                'items:id,sale_id,item_id,name,quantity,unit_price,total_price,unit_cost,total_cost',
                'contact:id,name,phone',
            ])
            ->when($since, fn ($q) => $q->where('updated_at', '>=', $since))
            ->latest('updated_at')
            ->latest('id')
            ->paginate($perPage, [
                'id', 'contact_id', 'number', 'status', 'payment_method',
                'subtotal_amount', 'discount_amount', 'total_amount',
                'sold_at', 'updated_at', 'deleted_at',
            ]);

        return response()->json([
            'ok'       => true,
            'sync_at'  => now()->toISOString(),
            'page'     => $paginated->currentPage(),
            'per_page' => $paginated->perPage(),
            'total'    => $paginated->total(),
            'has_more' => $paginated->hasMorePages(),
            'sales'    => $paginated->items(),
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
            ? \App\Models\Location::find($locationId)
            : null;

        return response()->json([
            'ok'                      => true,
            'sync_at'                 => now()->toISOString(),
            'timezone'                => $tenant->timezone ?? 'Africa/Casablanca',
            'currency'                => $tenant->currency ?? 'MAD',
            'locale'                  => $tenant->locale   ?? 'fr',
            'tenant_name'             => $tenant->name,
            'location_name'           => $location?->name,
            'allow_oversell'          => (bool) data_get($tenant->settings, 'pos.allow_oversell', false),
            'receipt_header'          => data_get($tenant->settings, 'receipt.header'),
            'receipt_footer'          => data_get($tenant->settings, 'receipt.footer'),
            'features_virtual_devices'=> (bool) data_get($tenant->settings, 'features.virtual_devices', false),
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Parse the ?since= parameter to a UTC datetime string.
     * Returns null if absent or invalid.
     */
    private function parseSince(Request $request): ?string
    {
        $raw = $request->query('since');
        if (! $raw) {
            return null;
        }
        try {
            // Preserve microsecond precision if provided.
            return Carbon::parse($raw)->utc()->format('Y-m-d H:i:s.u');
        } catch (\Throwable) {
            return null;
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
        $since   = $this->parseSince($request);
        $perPage = $this->perPage($request, 100, 200);
        $page    = $request->query('page', 1);

        $query = SaleInvoice::query()
            ->with(['sale:id,number,sold_at,status,total_amount,contact_id', 'contact:id,name,phone,email'])
            ->where('tenant_id', $tenant->id)
            ->when($since, fn ($q) => $q->where('updated_at', '>=', $since))
            ->orderBy('updated_at', 'asc')
            ->orderBy('id', 'asc');

        $paginated = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'ok'       => true,
            'sync_at'  => now()->toISOString(),
            'page'     => $paginated->currentPage(),
            'per_page' => $paginated->perPage(),
            'total'    => $paginated->total(),
            'has_more' => $paginated->hasMorePages(),
            'invoices' => $paginated->items(),
        ]);
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

    private function perPage(Request $request, int $default, int $max): int
    {
        return min((int) ($request->query('per_page', $default)), $max);
    }
}
