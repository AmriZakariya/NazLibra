<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Contact;
use App\Models\Item;
use App\Models\ItemLocationStock;
use App\Models\Tax;
use App\Models\Tenant;
use App\Models\Unit;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
    public function items(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant  = $request->attributes->get('api_tenant');
        $since   = $this->parseSince($request);
        $perPage = $this->perPage($request, self::DEFAULT_ITEMS_PER_PAGE, self::MAX_ITEMS_PER_PAGE);

        $page = $request->query('page', 1);

        $query = Item::query()
            ->where('tenant_id', $tenant->id)
            ->with([
                'category:id,name,slug',
                'brand:id,name',
                'unit:id,name',
                'tax:id,name,rate',
            ])
            ->when($since, fn ($q) => $q->where('updated_at', '>=', $since));

        $paginated = $query->paginate($perPage, [
            'id', 'category_id', 'brand_id', 'unit_id', 'tax_id',
            'type', 'status', 'is_enabled', 'checkout_visible', 'online_store_visible',
            'title', 'isbn', 'barcode', 'sku', 'custom_barcode1',
            'sale_price', 'purchase_price', 'min_stock_threshold',
            'stock_quantity', 'images', 'description', 'tags',
            'updated_at', 'created_at',
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
    public function stock(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant     = $request->attributes->get('api_tenant');
        $locationId = $request->attributes->get('api_location_id');

        if (! $locationId) {
            return response()->json(['ok' => false, 'message' => 'Aucun emplacement.'], 422);
        }

        $stocks = ItemLocationStock::query()
            ->where('tenant_id', $tenant->id)
            ->where('location_id', $locationId)
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
    public function contacts(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant  = $request->attributes->get('api_tenant');
        $since   = $this->parseSince($request);
        $perPage = $this->perPage($request, self::DEFAULT_CONTACT_PER_PAGE, self::MAX_CONTACT_PER_PAGE);

        $paginated = Contact::query()
            ->where('tenant_id', $tenant->id)
            ->where('type', 'customer')
            ->when($since, fn ($q) => $q->where('updated_at', '>=', $since))
            ->paginate($perPage, [
                'id', 'type', 'name', 'email', 'phone', 'address',
                'advance_balance', 'credit_balance',
                'updated_at', 'created_at',
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
    public function sales(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant  = $request->attributes->get('api_tenant');
        $since   = $this->parseSince($request);
        $perPage = $this->perPage($request, 100, 200);

        $paginated = \App\Models\Sale::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'paid')
            ->with([
                'items:id,sale_id,item_id,name,quantity,unit_price,total_price,unit_cost,total_cost',
                'contact:id,name,phone',
            ])
            ->when($since, fn ($q) => $q->where('updated_at', '>=', $since))
            ->latest('sold_at')
            ->paginate($perPage, [
                'id', 'contact_id', 'number', 'status', 'payment_method',
                'subtotal_amount', 'discount_amount', 'total_amount',
                'sold_at', 'updated_at',
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

    /** GET /api/v1/sync/catalog — legacy alias for /sync/items, kept for backward compat. */
    public function catalog(Request $request): JsonResponse
    {
        return $this->items($request);
    }

    private function perPage(Request $request, int $default, int $max): int
    {
        return min((int) ($request->query('per_page', $default)), $max);
    }
}
