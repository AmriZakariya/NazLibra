<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Contact;
use App\Models\Item;
use App\Models\ItemLocationStock;
use App\Models\OnlineOrder;
use App\Models\Tenant;
use App\Services\Inventory\InventoryService;
use App\Support\AppModules;
use App\Support\Locale;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class OnlineStoreController extends Controller
{
    public function index(Request $request, InventoryService $inventory): View
    {
        $tenant = $this->tenant($request);
        $this->ensureStoreEnabled($tenant);
        Locale::apply($tenant);

        $stores = $this->activeStoreCatalog($tenant);
        $pickupStore = $this->resolvePickupStore($tenant, $stores, (string) $request->query('pickup_store', ''));
        $locationId = $inventory->locationIdFromName($tenant->id, $pickupStore['name'] ?? null);
        $categorySlugs = $this->selectedValues($request->query('categories', []));
        $legacyCategorySlug = trim((string) $request->query('categorie', ''));
        if ($legacyCategorySlug !== '') {
            $categorySlugs = $categorySlugs->push($legacyCategorySlug)->unique()->values();
        }
        $selectedTags = $this->selectedValues($request->query('tags', []));
        $minPrice = $this->priceFilter($request->query('min_price'));
        $maxPrice = $this->priceFilter($request->query('max_price'));
        $includeOutOfStock = $request->has('include_out_of_stock')
            ? $request->boolean('include_out_of_stock')
            : true;
        $search = trim((string) $request->query('q', ''));

        $categories = Category::query()
            ->where('tenant_id', $tenant->id)
            ->whereHas('items', fn (Builder $query) => $this->onlineItemScope($query))
            ->orderBy('name')
            ->get();

        $availableTags = $this->onlineItemsQuery($tenant, $locationId)
            ->get()
            ->pluck('tags')
            ->flatMap(fn ($tags) => is_array($tags) ? $tags : (json_decode((string) $tags, true) ?: []))
            ->map(fn ($tag) => trim((string) $tag))
            ->filter()
            ->unique(fn (string $tag) => mb_strtolower($tag))
            ->sortBy(fn (string $tag) => mb_strtolower($tag))
            ->values();

        $items = $this->onlineItemsQuery($tenant, $locationId)
            ->when($categorySlugs->isNotEmpty(), fn (Builder $query) => $query->whereHas('category', fn (Builder $category) => $category->whereIn('slug', $categorySlugs->all())))
            ->when($selectedTags->isNotEmpty(), fn (Builder $query) => $query->where(function (Builder $query) use ($selectedTags): void {
                foreach ($selectedTags as $tag) {
                    $jsonTag = trim((string) json_encode($tag), '"');
                    $query->orWhere('items.tags', 'like', '%'.$tag.'%')
                        ->orWhere('items.tags', 'like', '%'.$jsonTag.'%');
                }
            }))
            ->when($minPrice !== null, fn (Builder $query) => $query->where('items.sale_price', '>=', $minPrice))
            ->when($maxPrice !== null, fn (Builder $query) => $query->where('items.sale_price', '<=', $maxPrice))
            ->when(! $includeOutOfStock, fn (Builder $query) => $query->where(function (Builder $query): void {
                $query->where('items.type', 'service')
                    ->orWhereRaw('coalesce(web_stock.quantity - web_stock.reserved_quantity, 0) > 0');
            }))
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($search): void {
                $query->where('items.title', 'like', "%{$search}%")
                    ->orWhere('items.item_code', 'like', "%{$search}%")
                    ->orWhere('items.sku', 'like', "%{$search}%")
                    ->orWhere('items.isbn', 'like', "%{$search}%")
                    ->orWhere('items.barcode', 'like', "%{$search}%")
                    ->orWhere('items.author', 'like', "%{$search}%")
                    ->orWhere('items.editor', 'like', "%{$search}%")
                    ->orWhere('items.tags', 'like', "%{$search}%")
                    ->orWhereHas('category', fn (Builder $category) => $category->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('brand', fn (Builder $brand) => $brand->where('name', 'like', "%{$search}%"));
            }))
            ->orderByRaw("case when items.type = 'service' then 0 when coalesce(web_stock.quantity - web_stock.reserved_quantity, 0) <= 0 then 2 else 1 end")
            ->orderBy('items.title')
            ->paginate(24)
            ->withQueryString();

        return view('storefront.index', [
            'tenant' => $tenant,
            'categories' => $categories,
            'availableTags' => $availableTags,
            'items' => $items,
            'categorySlug' => $categorySlugs->first() ?? '',
            'categorySlugs' => $categorySlugs,
            'selectedTags' => $selectedTags,
            'minPrice' => $minPrice,
            'maxPrice' => $maxPrice,
            'includeOutOfStock' => $includeOutOfStock,
            'search' => $search,
            'successOrderNumber' => $request->query('commande'),
            'stores' => $stores,
            'pickupStore' => $pickupStore,
        ]);
    }

    public function storeOrder(Request $request, InventoryService $inventory): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->ensureStoreEnabled($tenant);
        Locale::apply($tenant);

        $data = $request->validate([
            'customer_name' => ['required', 'string', 'max:180'],
            'customer_phone' => ['required', 'string', 'max:60', 'regex:/^\\+?[0-9\\s().-]{8,24}$/'],
            'customer_email' => ['nullable', 'email', 'max:180'],
            'delivery_address' => ['nullable', 'string', 'max:1000'],
            'customer_note' => ['nullable', 'string', 'max:1000'],
            'pickup_store' => ['nullable', 'string', 'max:120'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
        ]);
        $data['customer_phone'] = $this->normalizeMoroccanPhone($data['customer_phone']);

        $stores = $this->activeStoreCatalog($tenant);
        $pickupStore = $this->resolvePickupStore($tenant, $stores, (string) ($data['pickup_store'] ?? ''));
        $data['pickup_store'] = $pickupStore['key'] ?? null;
        $data['pickup_store_name'] = $pickupStore['name'] ?? null;
        $locationId = $inventory->locationIdFromName($tenant->id, $pickupStore['name'] ?? null);
        $itemIds = collect($data['items'])->pluck('item_id')->map(fn ($id) => (int) $id)->unique()->values();
        $catalogItems = Item::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('id', $itemIds)
            ->where('status', 'active')
            ->where('is_enabled', true)
            ->where('online_store_visible', true)
            ->with(['category', 'brand'])
            ->get()
            ->keyBy('id');

        $lines = collect($data['items'])
            ->map(function (array $line, int $index) use ($catalogItems, $inventory, $tenant, $locationId): array {
                $item = $catalogItems->get((int) $line['item_id']);
                if (! $item) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'items' => 'Un article du panier n’est plus disponible en boutique.',
                    ]);
                }

                $quantity = max(1, (int) $line['quantity']);
                $available = $item->type === 'service'
                    ? 999999
                    : $inventory->available($tenant->id, $item->id, null, $locationId);

                if ($item->type !== 'service' && $quantity > $available) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'items' => 'Stock insuffisant pour '.$item->title.'. Disponible: '.$available.'.',
                    ]);
                }

                $unitPrice = round((float) $item->sale_price, 2);

                return [
                    'item_id' => $item->id,
                    'name' => $item->title,
                    'code' => $item->barcode ?: ($item->isbn ?: ($item->sku ?: $item->item_code)),
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'discount_amount' => 0,
                    'total_amount' => round($quantity * $unitPrice, 2),
                    'note' => null,
                    'display_order' => $index + 1,
                ];
            })
            ->values();

        $subtotal = round($lines->sum('total_amount'), 2);
        $order = $this->createOrderWithRetry($tenant, $data, $lines, $subtotal);

        return redirect()
            ->route('storefront.index', ['commande' => $order->number])
            ->with('storefront_status', __('storefront.order_sent_full', ['number' => $order->number]));
    }

    private function tenant(Request $request): Tenant
    {
        return TenantContext::require($request, null, false);
    }

    private function ensureStoreEnabled(Tenant $tenant): void
    {
        abort_unless(AppModules::enabled($tenant, 'online_orders'), 404);
        abort_if(data_get($tenant->settings, 'online_store.enabled') === false, 404);
    }

    private function onlineItemsQuery(Tenant $tenant, int $locationId): Builder
    {
        $stockTable = (new ItemLocationStock())->getTable();

        $query = Item::query()
            ->from('items')
            ->with(['category', 'brand', 'unit'])
            ->leftJoin($stockTable.' as web_stock', function ($join) use ($tenant, $locationId): void {
                $join->on('web_stock.item_id', '=', 'items.id')
                    ->where('web_stock.tenant_id', '=', $tenant->id)
                    ->where('web_stock.location_id', '=', $locationId)
                    ->whereNull('web_stock.variant_id');
            })
            ->select('items.*')
            ->selectRaw('coalesce(web_stock.quantity - web_stock.reserved_quantity, 0) as online_available_stock')
            ->where('items.tenant_id', $tenant->id);

        return $this->onlineItemScope($query);
    }

    private function onlineItemScope(Builder $query): Builder
    {
        return $query
            ->where('items.status', 'active')
            ->where('items.is_enabled', true)
            ->where('items.online_store_visible', true);
    }

    private function createOrderWithRetry(Tenant $tenant, array $data, \Illuminate\Support\Collection $lines, float $subtotal): OnlineOrder
    {
        $lastException = null;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return DB::transaction(function () use ($tenant, $data, $lines, $subtotal): OnlineOrder {
                    $contact = Contact::query()
                        ->where('tenant_id', $tenant->id)
                        ->where('kind', 'client')
                        ->where(function (Builder $query) use ($data): void {
                            $query->where('phone', $data['customer_phone']);
                            if (! empty($data['customer_email'])) {
                                $query->orWhere('email', $data['customer_email']);
                            }
                        })
                        ->first();

                    if (! $contact) {
                        $contact = Contact::create([
                            'tenant_id' => $tenant->id,
                            'kind' => 'client',
                            'name' => $data['customer_name'],
                            'phone' => $data['customer_phone'],
                            'email' => $data['customer_email'] ?? null,
                            'address' => $data['delivery_address'] ?? null,
                            'tags' => ['boutique-web'],
                        ]);
                    } else {
                        $tags = collect($contact->tags ?? [])->push('boutique-web')->unique()->values()->all();
                        $contact->fill([
                            'name' => $data['customer_name'] ?: $contact->name,
                            'phone' => $data['customer_phone'],
                            'email' => $data['customer_email'] ?? $contact->email,
                            'address' => $data['delivery_address'] ?? $contact->address,
                            'tags' => $tags,
                        ])->save();
                    }

                    $order = OnlineOrder::create([
                        'tenant_id' => $tenant->id,
                        'contact_id' => $contact->id,
                        'user_id' => null,
                        'number' => $this->nextOnlineOrderNumber($tenant),
                        'channel' => 'online',
                        'status' => 'pending',
                        'payment_status' => 'unpaid',
                        'customer_name' => $data['customer_name'],
                        'customer_phone' => $data['customer_phone'],
                        'customer_email' => $data['customer_email'] ?? null,
                        'delivery_address' => $data['delivery_address'] ?? null,
                        'ordered_at' => Carbon::now($tenant->timezone ?: config('app.timezone')),
                        'subtotal_amount' => $subtotal,
                        'discount_amount' => 0,
                        'deposit_amount' => 0,
                        'total_amount' => $subtotal,
                        'customer_note' => $data['customer_note'] ?? null,
                        'internal_note' => 'Commande créée depuis la boutique en ligne.',
                        'metadata' => [
                            'source' => 'online_store',
                            'pickup_store' => $data['pickup_store'] ?? null,
                            'pickup_store_name' => $data['pickup_store_name'] ?? null,
                            'created_by' => null,
                            'created_by_name' => 'Client boutique',
                            'created_by_at' => now()->toIso8601String(),
                            'updated_by' => null,
                            'updated_by_name' => 'Client boutique',
                            'updated_by_at' => now()->toIso8601String(),
                            'ip' => request()->ip(),
                            'user_agent' => request()->userAgent(),
                        ],
                    ]);

                    foreach ($lines as $line) {
                        $order->items()->create($line);
                    }

                    return $order;
                });
            } catch (UniqueConstraintViolationException $exception) {
                $lastException = $exception;
            }
        }

        throw $lastException ?? new \RuntimeException('Impossible de créer la commande.');
    }

    private function nextOnlineOrderNumber(Tenant $tenant): string
    {
        $max = OnlineOrder::where('tenant_id', $tenant->id)
            ->where('number', 'like', 'PRE%')
            ->pluck('number')
            ->map(fn ($number) => (int) preg_replace('/\D+/', '', (string) $number))
            ->max() ?? 0;

        return 'PRE'.str_pad((string) ($max + 1), 5, '0', STR_PAD_LEFT);
    }

    private function normalizeMoroccanPhone(string $phone): string
    {
        $digits = preg_replace('/\\D+/', '', $phone) ?: '';

        if (str_starts_with($digits, '00212')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, '212')) {
            return '+'.$digits;
        }

        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            return '+212'.substr($digits, 1);
        }

        if (strlen($digits) === 9 && in_array($digits[0] ?? '', ['5', '6', '7'], true)) {
            return '+212'.$digits;
        }

        return '+'.$digits;
    }

    private function activeStoreCatalog(Tenant $tenant): \Illuminate\Support\Collection
    {
        $stores = collect(data_get($tenant->settings, 'stores', []))
            ->map(function ($store): array {
                if (! is_array($store)) {
                    $store = ['name' => (string) $store];
                }

                $name = trim((string) ($store['name'] ?? 'Magasin principal'));

                return [
                    'key' => (string) ($store['key'] ?? Str::slug($name)),
                    'name' => $name,
                    'type' => (string) ($store['type'] ?? 'store'),
                    'address' => $store['address'] ?? null,
                    'phone' => $store['phone'] ?? null,
                    'is_active' => (bool) ($store['is_active'] ?? true),
                ];
            })
            ->filter(fn (array $store): bool => $store['name'] !== '' && $store['is_active'])
            ->unique('key')
            ->values();

        if ($stores->isEmpty()) {
            $stores = collect([[
                'key' => 'magasin-principal',
                'name' => 'Magasin principal',
                'type' => 'store',
                'address' => $tenant->address,
                'phone' => $tenant->phone,
                'is_active' => true,
            ]]);
        }

        return $stores;
    }

    private function resolvePickupStore(Tenant $tenant, \Illuminate\Support\Collection $stores, string $requestedKey = ''): array
    {
        $configuredKey = (string) data_get($tenant->settings, 'online_store.pickup_store', data_get($tenant->settings, 'current_store', ''));
        $key = trim($requestedKey) !== '' ? trim($requestedKey) : $configuredKey;

        return $stores->firstWhere('key', $key)
            ?? $stores->firstWhere('key', data_get($tenant->settings, 'current_store'))
            ?? $stores->first();
    }

    private function selectedValues(mixed $value): \Illuminate\Support\Collection
    {
        return collect(is_array($value) ? $value : [$value])
            ->flatMap(fn ($entry) => is_array($entry) ? $entry : explode(',', (string) $entry))
            ->map(fn ($entry) => trim((string) $entry))
            ->filter()
            ->unique()
            ->values();
    }

    private function priceFilter(mixed $value): ?float
    {
        $value = str_replace([' ', ','], ['', '.'], trim((string) $value));

        if ($value === '' || ! is_numeric($value)) {
            return null;
        }

        return max(0, round((float) $value, 2));
    }

}
