<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\ItemVariant;
use App\Models\Tenant;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Yajra\DataTables\Facades\DataTables;

class VariantController extends Controller
{
    public function index(Request $request): View
    {
        $tenant = $this->tenant();
        $user = $request->user();
        $locale = \App\Support\Locale::current($tenant);
        $tr = fn (string $text): string => \App\Support\Locale::t($text, $locale);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'product' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:active,inactive,out_of_stock'],
            'stock' => ['nullable', 'in:all,low,out,available'],
            'sort' => ['nullable', 'in:name,product,price,stock,status,updated_at,created_at'],
            'direction' => ['nullable', 'in:asc,desc'],
        ]);

        $query = ItemVariant::query()
            ->where('tenant_id', $tenant->id)
            ->with(['item.category'])
            ->search($filters['q'] ?? '')
            ->filterByStatus($filters['status'] ?? 'all')
            ->filterByStock($filters['stock'] ?? 'all');

        if (! empty($filters['product'])) {
            $query->filterByProduct($filters['product']);
        }

        $sort = $filters['sort'] ?? 'name';
        $direction = $filters['direction'] ?? 'asc';

        $sortMap = [
            'name' => 'name',
            'product' => 'item_id',
            'price' => 'sale_price',
            'stock' => 'stock_quantity',
            'status' => 'status',
            'updated_at' => 'updated_at',
            'created_at' => 'created_at',
        ];
        $sortColumn = $sortMap[$sort] ?? 'name';

        $variants = $query->orderBy($sortColumn, $direction)
            ->orderBy('sort_order', 'asc')
            ->orderBy('id', 'desc')
            ->paginate(25)
            ->withQueryString();

        $products = $tenant->items()
            ->where('type', '!=', 'service')
            ->where(function ($query): void {
                $query->where('item_group', 'Variants')
                    ->orWhereHas('variants');
            })
            ->orderBy('title')
            ->get();

        return view('variants.index', [
            'tenant' => $tenant,
            'active' => 'catalog',
            'variants' => $variants,
            'products' => $products,
            'filters' => [
                'q' => $filters['q'] ?? '',
                'product' => $filters['product'] ?? '',
                'status' => $filters['status'] ?? 'all',
                'stock' => $filters['stock'] ?? 'all',
                'sort' => $sort,
                'direction' => $direction,
            ],
            'tr' => $tr,
            'locale' => $locale,
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $tenant = $this->tenant();

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'product' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:active,inactive,out_of_stock'],
            'stock' => ['nullable', 'in:all,low,out,available'],
        ]);

        $query = ItemVariant::query()
            ->where('tenant_id', $tenant->id)
            ->with(['item.category'])
            ->search($filters['q'] ?? '')
            ->filterByStatus($filters['status'] ?? 'all')
            ->filterByStock($filters['stock'] ?? 'all');

        if (! empty($filters['product'])) {
            $query->filterByProduct($filters['product']);
        }

        return DataTables::eloquent($query)
            ->editColumn('name', function (ItemVariant $variant) {
                return '<strong class="text-sm font-semibold">'.e($variant->name).'</strong>';
            })
            ->addColumn('product', function (ItemVariant $variant) {
                $item = $variant->item;
                if (! $item) return '<span class="text-slate-400">—</span>';
                return '<span class="text-sm">'.e($item->title).'</span><p class="text-xs text-slate-400">'.e($item->category?->name ?? 'Sans catégorie').'</p>';
            })
            ->addColumn('identifiers', function (ItemVariant $variant) {
                $lines = [];
                if ($variant->barcode) $lines[] = '<span class="text-xs font-mono">CB: '.e($variant->barcode).'</span>';
                if ($variant->sku) $lines[] = '<span class="text-xs font-mono">SKU: '.e($variant->sku).'</span>';
                if ($variant->isbn) $lines[] = '<span class="text-xs font-mono">ISBN: '.e($variant->isbn).'</span>';
                return $lines ? '<div class="space-y-0.5">'.implode('', $lines).'</div>' : '<span class="text-xs text-slate-400">—</span>';
            })
            ->addColumn('price', function (ItemVariant $variant) {
                $price = number_format($variant->sale_price, 2, ',', ' ');
                $cost = number_format($variant->purchase_price, 2, ',', ' ');
                $margin = $variant->margin_percent;
                return '<span class="text-sm font-semibold">'.$price.' DH</span><p class="text-xs text-slate-400">Achat: '.$cost.' DH</p><p class="text-xs '.($margin > 0 ? 'text-emerald-600' : 'text-rose-600').'">Marge: '.$margin.'%</p>';
            })
            ->addColumn('stock', function (ItemVariant $variant) {
                $qty = $variant->stock_quantity;
                $class = 'bg-emerald-50 text-emerald-700';
                if ($variant->is_out_of_stock) $class = 'bg-rose-50 text-rose-700';
                elseif ($variant->is_low_stock) $class = 'bg-amber-50 text-amber-700';
                return '<span class="inline-flex rounded-md px-2 py-0.5 text-xs font-bold '.$class.'">'.e($qty).'</span>';
            })
            ->addColumn('status_badge', function (ItemVariant $variant) {
                $map = [
                    'active' => 'bg-emerald-50 text-emerald-700',
                    'inactive' => 'bg-slate-100 text-slate-600',
                    'out_of_stock' => 'bg-rose-50 text-rose-700',
                ];
                $label = ['active' => 'Actif', 'inactive' => 'Inactif', 'out_of_stock' => 'Rupture'][$variant->status] ?? $variant->status;
                $class = $map[$variant->status] ?? 'bg-slate-100 text-slate-600';
                return '<span class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium '.$class.'">'.e($label).'</span>';
            })
            ->addColumn('actions', function (ItemVariant $variant) {
                $btns = '';
                $btns .= '<a href="'.route('variants.show', $variant).'" class="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2 py-1 text-xs font-semibold transition hover:border-brand hover:text-brand dark:border-white/10">Détail</a>';
                $btns .= ' <a href="'.route('variants.edit', $variant).'" class="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2 py-1 text-xs font-semibold transition hover:border-brand hover:text-brand dark:border-white/10">Modifier</a>';
                return $btns;
            })
            ->addColumn('product_name', fn (ItemVariant $v) => e($v->item?->title ?? ''))
            ->addColumn('category_name', fn (ItemVariant $v) => e($v->item?->category?->name ?? ''))
            ->rawColumns(['name', 'product', 'identifiers', 'price', 'stock', 'status_badge', 'actions'])
            ->toJson();
    }

    public function show(Request $request, ItemVariant $variant): View
    {
        $tenant = $this->tenant();
        abort_unless((int) $variant->tenant_id === (int) $tenant->id, 404);

        $variant->load(['item', 'item.category', 'item.brand', 'item.unit']);

        $locale = \App\Support\Locale::current($tenant);
        $tr = fn (string $text): string => \App\Support\Locale::t($text, $locale);

        return view('variants.show', [
            'tenant' => $tenant,
            'active' => 'catalog',
            'variant' => $variant,
            'tr' => $tr,
            'locale' => $locale,
        ]);
    }

    public function create(Request $request): View
    {
        $tenant = $this->tenant();
        $locale = \App\Support\Locale::current($tenant);
        $tr = fn (string $text): string => \App\Support\Locale::t($text, $locale);

        $products = $tenant->items()
            ->where('type', '!=', 'service')
            ->orderBy('title')
            ->get();

        $preselectedItem = null;
        if ($request->filled('item')) {
            $preselectedItem = $tenant->items()->whereKey((int) $request->query('item'))->first();
        }

        return view('variants.form', [
            'tenant' => $tenant,
            'active' => 'catalog',
            'variant' => null,
            'products' => $products,
            'preselectedItem' => $preselectedItem,
            'tr' => $tr,
            'locale' => $locale,
        ]);
    }

    public function edit(Request $request, ItemVariant $variant): View
    {
        $tenant = $this->tenant();
        abort_unless((int) $variant->tenant_id === (int) $tenant->id, 404);

        $locale = \App\Support\Locale::current($tenant);
        $tr = fn (string $text): string => \App\Support\Locale::t($text, $locale);

        $products = $tenant->items()
            ->where('type', '!=', 'service')
            ->orderBy('title')
            ->get();

        return view('variants.form', [
            'tenant' => $tenant,
            'active' => 'catalog',
            'variant' => $variant,
            'products' => $products,
            'preselectedItem' => null,
            'tr' => $tr,
            'locale' => $locale,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $data = $request->validate([
            'item_id' => ['required', 'integer', Rule::exists('items', 'id')->where('tenant_id', $tenant->id)],
            'name' => ['required', 'string', 'max:255'],
            'barcode' => ['nullable', 'string', 'max:120'],
            'sku' => ['nullable', 'string', 'max:120'],
            'isbn' => ['nullable', 'string', 'max:120'],
            'language' => ['nullable', 'string', 'max:20'],
            'edition' => ['nullable', 'string', 'max:120'],
            'format' => ['nullable', 'string', 'max:120'],
            'publisher' => ['nullable', 'string', 'max:160'],
            'author' => ['nullable', 'string', 'max:160'],
            'purchase_price' => ['required', 'numeric', 'min:0'],
            'sale_price' => ['required', 'numeric', 'min:0'],
            'stock_quantity' => ['required', 'integer', 'min:0'],
            'min_stock_threshold' => ['nullable', 'integer', 'min:0'],
            'status' => ['required', 'in:active,inactive,out_of_stock'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        // Validate barcode uniqueness
        if (! empty($data['barcode'])) {
            $barcodeExists = Item::where('tenant_id', $tenant->id)
                ->where('barcode', $data['barcode'])
                ->exists();
            if (! $barcodeExists) {
                $barcodeExists = ItemVariant::where('tenant_id', $tenant->id)
                    ->where('barcode', $data['barcode'])
                    ->exists();
            }
            if ($barcodeExists) {
                throw ValidationException::withMessages(['barcode' => 'Ce code-barres est déjà utilisé.']);
            }
        }

        // Validate SKU uniqueness
        if (! empty($data['sku'])) {
            $skuExists = ItemVariant::where('tenant_id', $tenant->id)
                ->where('sku', $data['sku'])
                ->exists();
            if ($skuExists) {
                throw ValidationException::withMessages(['sku' => 'Ce SKU est déjà utilisé.']);
            }
        }

        // Validate ISBN uniqueness
        if (! empty($data['isbn'])) {
            $isbnExists = Item::where('tenant_id', $tenant->id)
                ->where('isbn', $data['isbn'])
                ->exists();
            if (! $isbnExists) {
                $isbnExists = ItemVariant::where('tenant_id', $tenant->id)
                    ->where('isbn', $data['isbn'])
                    ->exists();
            }
            if ($isbnExists) {
                throw ValidationException::withMessages(['isbn' => 'Cet ISBN est déjà utilisé.']);
            }
        }

        $variant = ItemVariant::create([
            'tenant_id' => $tenant->id,
            'item_id' => $data['item_id'],
            'name' => $data['name'],
            'barcode' => $data['barcode'] ?: null,
            'sku' => $data['sku'] ?: null,
            'isbn' => $data['isbn'] ?: null,
            'language' => $data['language'] ?: null,
            'edition' => $data['edition'] ?: null,
            'format' => $data['format'] ?: null,
            'publisher' => $data['publisher'] ?: null,
            'author' => $data['author'] ?: null,
            'purchase_price' => round((float) $data['purchase_price'], 2),
            'sale_price' => round((float) $data['sale_price'], 2),
            'stock_quantity' => (int) $data['stock_quantity'],
            'min_stock_threshold' => (int) ($data['min_stock_threshold'] ?? 0),
            'status' => $data['status'],
            'is_active' => (bool) ($data['is_active'] ?? true),
            'notes' => $data['notes'] ?: null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);

        return redirect()->route('variants.show', $variant)->with('status', 'Variante créée avec succès.');
    }

    public function update(Request $request, ItemVariant $variant): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless((int) $variant->tenant_id === (int) $tenant->id, 404);

        $data = $request->validate([
            'item_id' => ['required', 'integer', Rule::exists('items', 'id')->where('tenant_id', $tenant->id)],
            'name' => ['required', 'string', 'max:255'],
            'barcode' => ['nullable', 'string', 'max:120'],
            'sku' => ['nullable', 'string', 'max:120'],
            'isbn' => ['nullable', 'string', 'max:120'],
            'language' => ['nullable', 'string', 'max:20'],
            'edition' => ['nullable', 'string', 'max:120'],
            'format' => ['nullable', 'string', 'max:120'],
            'publisher' => ['nullable', 'string', 'max:160'],
            'author' => ['nullable', 'string', 'max:160'],
            'purchase_price' => ['required', 'numeric', 'min:0'],
            'sale_price' => ['required', 'numeric', 'min:0'],
            'stock_quantity' => ['required', 'integer', 'min:0'],
            'min_stock_threshold' => ['nullable', 'integer', 'min:0'],
            'status' => ['required', 'in:active,inactive,out_of_stock'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        // Validate barcode uniqueness (excluding current variant)
        if (! empty($data['barcode']) && $data['barcode'] !== $variant->barcode) {
            $barcodeExists = Item::where('tenant_id', $tenant->id)
                ->where('barcode', $data['barcode'])
                ->exists();
            if (! $barcodeExists) {
                $barcodeExists = ItemVariant::where('tenant_id', $tenant->id)
                    ->where('barcode', $data['barcode'])
                    ->where('id', '!=', $variant->id)
                    ->exists();
            }
            if ($barcodeExists) {
                throw ValidationException::withMessages(['barcode' => 'Ce code-barres est déjà utilisé.']);
            }
        }

        // Validate SKU uniqueness (excluding current variant)
        if (! empty($data['sku']) && $data['sku'] !== $variant->sku) {
            $skuExists = ItemVariant::where('tenant_id', $tenant->id)
                ->where('sku', $data['sku'])
                ->where('id', '!=', $variant->id)
                ->exists();
            if ($skuExists) {
                throw ValidationException::withMessages(['sku' => 'Ce SKU est déjà utilisé.']);
            }
        }

        // Validate ISBN uniqueness (excluding current variant)
        if (! empty($data['isbn']) && $data['isbn'] !== $variant->isbn) {
            $isbnExists = Item::where('tenant_id', $tenant->id)
                ->where('isbn', $data['isbn'])
                ->exists();
            if (! $isbnExists) {
                $isbnExists = ItemVariant::where('tenant_id', $tenant->id)
                    ->where('isbn', $data['isbn'])
                    ->where('id', '!=', $variant->id)
                    ->exists();
            }
            if ($isbnExists) {
                throw ValidationException::withMessages(['isbn' => 'Cet ISBN est déjà utilisé.']);
            }
        }

        $variant->update([
            'item_id' => $data['item_id'],
            'name' => $data['name'],
            'barcode' => $data['barcode'] ?: null,
            'sku' => $data['sku'] ?: null,
            'isbn' => $data['isbn'] ?: null,
            'language' => $data['language'] ?: null,
            'edition' => $data['edition'] ?: null,
            'format' => $data['format'] ?: null,
            'publisher' => $data['publisher'] ?: null,
            'author' => $data['author'] ?: null,
            'purchase_price' => round((float) $data['purchase_price'], 2),
            'sale_price' => round((float) $data['sale_price'], 2),
            'stock_quantity' => (int) $data['stock_quantity'],
            'min_stock_threshold' => (int) ($data['min_stock_threshold'] ?? 0),
            'status' => $data['status'],
            'is_active' => (bool) ($data['is_active'] ?? true),
            'notes' => $data['notes'] ?: null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);

        return redirect()->route('variants.show', $variant)->with('status', 'Variante mise à jour.');
    }

    public function duplicate(Request $request, ItemVariant $variant): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless((int) $variant->tenant_id === (int) $tenant->id, 404);

        $new = $variant->replicate();
        $new->name = $variant->name . ' (copie)';
        $new->barcode = null;
        $new->sku = null;
        $new->isbn = null;
        $new->status = 'inactive';
        $new->is_active = false;
        $new->stock_quantity = 0;
        $new->save();

        return redirect()->route('variants.edit', $new)->with('status', 'Variante dupliquée. Modifiez les informations avant de l\'activer.');
    }

    public function toggle(Request $request, ItemVariant $variant): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless((int) $variant->tenant_id === (int) $tenant->id, 404);

        $variant->update([
            'is_active' => ! $variant->is_active,
            'status' => $variant->is_active ? 'inactive' : ($variant->stock_quantity > 0 ? 'active' : 'out_of_stock'),
        ]);

        return back()->with('status', $variant->is_active ? 'Variante activée.' : 'Variante désactivée.');
    }

    public function destroy(Request $request, ItemVariant $variant): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless((int) $variant->tenant_id === (int) $tenant->id, 404);

        $variant->update([
            'is_active' => false,
            'status' => 'inactive',
        ]);

        return redirect()->route('variants.index')->with('status', 'Variante désactivée.');
    }
}
