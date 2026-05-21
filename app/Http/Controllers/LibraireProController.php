<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Brand;
use App\Models\Contact;
use App\Models\Item;
use App\Models\ItemVariant;
use App\Models\Loan;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Tax;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\VariantOption;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;
use ZipArchive;

class LibraireProController extends Controller
{
    public function dashboard(): View
    {
        $tenant = $this->tenant();
        $today = Carbon::today();

        $dailyRevenue = $tenant->sales()->whereDate('sold_at', $today)->sum('total_amount');
        $dailyItems = DB::table('sale_items')
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->where('sales.tenant_id', $tenant->id)
            ->whereDate('sales.sold_at', $today)
            ->sum('sale_items.quantity');

        $salesTrend = $tenant->sales()
            ->selectRaw('date(sold_at) as day, sum(total_amount) as total')
            ->where('sold_at', '>=', now()->subDays(6)->startOfDay())
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        return view('librairepro.dashboard', [
            'tenant' => $tenant,
            'active' => 'dashboard',
            'stats' => [
                ['label' => 'Chiffre du jour', 'value' => $this->money($dailyRevenue), 'tone' => 'success', 'delta' => '+18% vs hier'],
                ['label' => 'Articles vendus', 'value' => number_format((float) $dailyItems, 0, ',', ' '), 'tone' => 'info', 'delta' => 'pic 11h-13h'],
                ['label' => 'Nouveaux clients', 'value' => Contact::where('tenant_id', $tenant->id)->whereDate('created_at', $today)->count(), 'tone' => 'primary', 'delta' => 'CRM actif'],
                ['label' => 'Emprunts actifs', 'value' => $tenant->loans()->whereIn('status', ['borrowed', 'overdue'])->count(), 'tone' => 'warning', 'delta' => $tenant->loans()->where('status', 'overdue')->count().' en retard'],
            ],
            'lowStockItems' => $tenant->items()->with('category')->whereColumn('stock_quantity', '<=', 'min_stock_threshold')->orderBy('stock_quantity')->get(),
            'recentSales' => $tenant->sales()->with('contact')->latest('sold_at')->take(6)->get(),
            'activeLoans' => $tenant->loans()->with(['member', 'item'])->whereIn('status', ['borrowed', 'overdue'])->latest()->take(5)->get(),
            'salesTrend' => $salesTrend,
            'topItems' => DB::table('sale_items')
                ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
                ->where('sales.tenant_id', $tenant->id)
                ->selectRaw('sale_items.name, sum(sale_items.quantity) as quantity, sum(sale_items.total_price) as revenue')
                ->groupBy('sale_items.name')
                ->orderByDesc('quantity')
                ->limit(5)
                ->get(),
        ]);
    }

    public function catalog(Request $request): View
    {
        $tenant = $this->tenant();
        $panel = $request->query('panel', 'articles');
        $query = trim((string) $request->query('q'));
        $status = $request->query('status', 'all');
        $type = $request->query('type', 'all');
        $category = $request->query('category', 'all');
        $stock = $request->query('stock', 'all');
        $perPage = (int) $request->query('per_page', 25);
        $perPage = in_array($perPage, [10, 25, 50, 100], true) ? $perPage : 25;
        $sort = $request->query('sort', 'title');
        $direction = $request->query('direction') === 'desc' ? 'desc' : 'asc';
        $sorts = ['title', 'barcode', 'stock_quantity', 'min_stock_threshold', 'sale_price', 'status', 'created_at'];
        $sort = in_array($sort, $sorts, true) ? $sort : 'title';

        $itemsQuery = $tenant->items()
            ->with(['category', 'brand', 'unit', 'tax', 'variants'])
            ->when($query, fn (Builder $builder) => $builder->where(function (Builder $builder) use ($query): void {
                $builder->where('title', 'like', "%{$query}%")
                    ->orWhere('item_code', 'like', "%{$query}%")
                    ->orWhere('sku', 'like', "%{$query}%")
                    ->orWhere('isbn', 'like', "%{$query}%")
                    ->orWhere('barcode', 'like', "%{$query}%")
                    ->orWhere('author', 'like', "%{$query}%");
            }))
            ->when($status !== 'all', fn (Builder $builder) => $builder->where('status', $status))
            ->when($category !== 'all', fn (Builder $builder) => $builder->where('category_id', $category))
            ->when($stock === 'low', fn (Builder $builder) => $builder->whereColumn('stock_quantity', '<=', 'min_stock_threshold'))
            ->when($stock === 'out', fn (Builder $builder) => $builder->where('stock_quantity', '<=', 0));

        if (in_array($panel, ['services', 'ajouter-service'], true)) {
            $itemsQuery->where('type', 'service');
            $type = 'service';
        } elseif ($type !== 'all') {
            $itemsQuery->where('type', $type);
        } else {
            $itemsQuery->where('type', '!=', 'service');
        }

        $items = $itemsQuery
            ->orderBy($sort, $direction)
            ->paginate($perPage)
            ->withQueryString();

        $editItem = null;
        if ($request->filled('edit')) {
            $editItem = $tenant->items()
                ->with(['category', 'brand', 'unit', 'tax', 'variants'])
                ->whereKey((int) $request->query('edit'))
                ->first();
        }

        return view('librairepro.catalog', [
            'tenant' => $tenant,
            'active' => 'catalog',
            'items' => $items,
            'categories' => Category::where('tenant_id', $tenant->id)->with(['parent'])->withCount('items')->orderBy('name')->get(),
            'brands' => Brand::where('tenant_id', $tenant->id)->orderBy('name')->get(),
            'units' => Unit::where('tenant_id', $tenant->id)->orderBy('name')->get(),
            'taxes' => Tax::where('tenant_id', $tenant->id)->orderBy('name')->get(),
            'services' => $panel === 'services' ? $items : $tenant->items()->with(['category', 'brand'])->where('type', 'service')->orderBy('title')->paginate(12, ['*'], 'services_page'),
            'variantItems' => $tenant->items()
                ->with('variants')
                ->withCount('variants')
                ->where('type', '!=', 'service')
                ->orderByDesc('variants_count')
                ->orderBy('title')
                ->take(50)
                ->get(),
            'variantOptions' => VariantOption::where('tenant_id', $tenant->id)->orderBy('name')->get(),
            'editItem' => $editItem,
            'catalogStats' => [
                'items' => $tenant->items()->where('type', '!=', 'service')->count(),
                'services' => $tenant->items()->where('type', 'service')->count(),
                'low' => $tenant->items()->whereColumn('stock_quantity', '<=', 'min_stock_threshold')->count(),
                'value' => $tenant->items()->selectRaw('sum(stock_quantity * purchase_price) as value')->value('value') ?? 0,
            ],
            'query' => $query,
            'status' => $status,
            'type' => $type,
            'categoryFilter' => $category,
            'stock' => $stock,
            'sort' => $sort,
            'direction' => $direction,
            'perPage' => $perPage,
        ]);
    }

    public function exportCatalog(Request $request): StreamedResponse
    {
        $tenant = $this->tenant();
        $panel = $request->query('panel', 'articles');
        $query = trim((string) $request->query('q'));
        $status = $request->query('status', 'all');
        $type = $request->query('type', 'all');
        $category = $request->query('category', 'all');
        $stock = $request->query('stock', 'all');

        $items = $tenant->items()
            ->with(['category', 'unit', 'tax'])
            ->when($query, fn (Builder $builder) => $builder->where(function (Builder $builder) use ($query): void {
                $builder->where('title', 'like', "%{$query}%")
                    ->orWhere('item_code', 'like', "%{$query}%")
                    ->orWhere('sku', 'like', "%{$query}%")
                    ->orWhere('isbn', 'like', "%{$query}%")
                    ->orWhere('barcode', 'like', "%{$query}%")
                    ->orWhere('author', 'like', "%{$query}%");
            }))
            ->when($status !== 'all', fn (Builder $builder) => $builder->where('status', $status))
            ->when($category !== 'all', fn (Builder $builder) => $builder->where('category_id', $category))
            ->when($stock === 'low', fn (Builder $builder) => $builder->whereColumn('stock_quantity', '<=', 'min_stock_threshold'))
            ->when($stock === 'out', fn (Builder $builder) => $builder->where('stock_quantity', '<=', 0))
            ->when($panel === 'services', fn (Builder $builder) => $builder->where('type', 'service'))
            ->when($panel !== 'services' && $type !== 'all', fn (Builder $builder) => $builder->where('type', $type))
            ->when($panel !== 'services' && $type === 'all', fn (Builder $builder) => $builder->where('type', '!=', 'service'))
            ->orderBy('title')
            ->get();

        $filename = 'catalogue-'.($panel === 'services' ? 'services' : 'articles').'-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($items): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Image', 'Code de barre', "Nom de l'article", "Catégorie/Type d'élément", 'Unité', 'Stock', "Quantité d'alerte", 'Prix de vente', 'Impôt', 'Statut', 'Action']);

            foreach ($items as $item) {
                fputcsv($handle, [
                    collect($item->images)->first() ?? '',
                    $item->barcode ?? $item->isbn ?? $item->sku ?? '',
                    $item->title,
                    ($item->category?->name ?? 'Sans catégorie').' / '.$this->typeLabel($item->type),
                    $item->unit?->name ?? '',
                    $item->type === 'service' ? 'Illimité' : $item->stock_quantity,
                    $item->min_stock_threshold,
                    $item->sale_price,
                    $item->tax ? $item->tax->name.' ('.number_format((float) $item->tax->rate, 2, '.', '').'%)' : '',
                    $this->statusLabel($item->status),
                    route('catalog', ['panel' => $item->type === 'service' ? 'services' : 'articles', 'edit' => $item->id]),
                ]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function storeItem(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $data = $this->validatedItem($request);
        $data['tenant_id'] = $tenant->id;
        $data['status'] = $data['stock_quantity'] <= 0 && ($data['type'] ?? 'book') !== 'service' ? 'out_of_stock' : ($data['status'] ?? 'active');

        Item::create($data);

        return back()->with('status', 'Article ajouté au catalogue.');
    }

    public function updateItem(Request $request, Item $item): RedirectResponse
    {
        $this->authorizeTenantItem($item);
        $data = $this->validatedItem($request, $item);
        $data['status'] = $data['stock_quantity'] <= 0 && ($data['type'] ?? $item->type) !== 'service' ? 'out_of_stock' : ($data['status'] ?? $item->status);

        $item->update($data);

        return back()->with('status', 'Article mis à jour.');
    }

    public function destroyItem(Item $item): RedirectResponse
    {
        $this->authorizeTenantItem($item);
        $item->delete();

        return back()->with('status', 'Article supprimé.');
    }

    public function storeCategory(Request $request): RedirectResponse|\Illuminate\Http\JsonResponse
    {
        $tenant = $this->tenant();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'exists:categories,id'],
            'icon' => ['nullable', 'string', 'max:64'],
            'color' => ['nullable', 'string', 'max:16'],
            'description' => ['nullable', 'string', 'max:2000'],
            'loan_duration_days' => ['required', 'integer', 'min:1', 'max:365'],
            'daily_fine_amount' => ['required', 'numeric', 'min:0', 'max:9999'],
        ]);

        if (! empty($data['parent_id'])) {
            abort_unless(Category::where('tenant_id', $tenant->id)->where('id', $data['parent_id'])->exists(), 403);
        }

        $data['tenant_id'] = $tenant->id;
        $data['slug'] = $this->uniqueSlug(Category::class, $tenant->id, $data['name']);
        $data['icon'] = ($data['icon'] ?? null) ?: 'book-open';
        $data['color'] = ($data['color'] ?? null) ?: '#4F46E5';

        $category = Category::create($data);

        if ($request->expectsJson()) {
            return response()->json(['id' => $category->id, 'label' => $category->name]);
        }

        return back()->with('status', 'Catégorie créée.');
    }

    public function storeBrand(Request $request): RedirectResponse|\Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:publisher,brand'],
            'description' => ['nullable', 'string', 'max:2000'],
            'phone' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
        ]);

        $data['tenant_id'] = $this->tenant()->id;
        $brand = Brand::create($data);

        if ($request->expectsJson()) {
            return response()->json(['id' => $brand->id, 'label' => $brand->name]);
        }

        return back()->with('status', 'Marque ou éditeur ajouté.');
    }

    public function storeUnit(Request $request): RedirectResponse|\Illuminate\Http\JsonResponse
    {
        $tenant = $this->tenant();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $unit = Unit::firstOrCreate(
            ['tenant_id' => $tenant->id, 'name' => $data['name']],
            ['description' => $data['description'] ?? null],
        );

        if ($request->expectsJson()) {
            return response()->json(['id' => $unit->id, 'label' => $unit->name]);
        }

        return back()->with('status', 'Unité ajoutée.');
    }

    public function storeTax(Request $request): RedirectResponse|\Illuminate\Http\JsonResponse
    {
        $tenant = $this->tenant();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $tax = Tax::firstOrCreate(
            ['tenant_id' => $tenant->id, 'name' => $data['name']],
            ['rate' => $data['rate'], 'description' => $data['description'] ?? null],
        );

        if ($request->expectsJson()) {
            return response()->json(['id' => $tax->id, 'label' => $tax->name.' ('.number_format((float) $tax->rate, 2, ',', ' ').'%)']);
        }

        return back()->with('status', 'Taxe ajoutée.');
    }

    public function storeVariant(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $data = $request->validate([
            'item_id' => ['required', 'exists:items,id'],
            'name' => ['required', 'string', 'max:255'],
            'format' => ['nullable', 'string', 'max:120'],
            'size' => ['nullable', 'string', 'max:120'],
            'color' => ['nullable', 'string', 'max:120'],
            'barcode' => ['nullable', 'string', 'max:120'],
            'purchase_price' => ['required', 'numeric', 'min:0'],
            'sale_price' => ['required', 'numeric', 'min:0'],
            'stock_quantity' => ['required', 'integer', 'min:0'],
        ]);

        abort_unless(Item::where('tenant_id', $tenant->id)->where('id', $data['item_id'])->exists(), 403);

        ItemVariant::create([
            'item_id' => $data['item_id'],
            'name' => $data['name'],
            'attributes' => array_filter([
                'format' => $data['format'] ?? null,
                'taille' => $data['size'] ?? null,
                'couleur' => $data['color'] ?? null,
            ]),
            'barcode' => $data['barcode'] ?: null,
            'purchase_price' => $data['purchase_price'],
            'sale_price' => $data['sale_price'],
            'stock_quantity' => $data['stock_quantity'],
        ]);

        return back()->with('status', 'Variante ajoutée.');
    }

    public function importItems(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $data = $request->validate([
            'catalog_file' => ['required', 'file', 'max:20480'],
            'kind' => ['required', 'in:items,services,categories,brands,variants'],
        ]);

        $rows = $this->rowsFromUpload($request->file('catalog_file'));

        if ($data['kind'] === 'categories') {
            return $this->importCategories($tenant, $rows);
        }

        if ($data['kind'] === 'brands') {
            return $this->importBrands($tenant, $rows);
        }

        if ($data['kind'] === 'variants') {
            return $this->importVariantOptions($tenant, $rows);
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $title = trim((string) $this->rowValue($row, ['title', 'titre', 'name', 'nom', 'item_name', 'nom_article', 'nom_de_l_article']));

            if ($title === '') {
                $skipped++;
                continue;
            }

            $category = $this->categoryByName($tenant->id, $this->cleanLegacyCategoryName((string) ($this->rowValue($row, ['category', 'categorie', 'category_name', 'nom_categorie', 'categorie_type_d_element']) ?: ($data['kind'] === 'services' ? 'Services' : 'Import'))));
            $brand = $this->brandByName($tenant->id, (string) $this->rowValue($row, ['brand', 'brand_name', 'publisher', 'editeur', 'marque']));
            $unit = $this->unitByName($tenant->id, (string) ($this->rowValue($row, ['unit', 'unit_name', 'unite', 'nom_unite']) ?: ($data['kind'] === 'services' ? 'Service' : 'Pièce')));
            [$taxName, $taxRate] = $this->taxParts((string) ($this->rowValue($row, ['tax', 'tax_name', 'taxe', 'impot']) ?: 'Sans TVA'), $this->rowValue($row, ['tax_rate', 'tax_value', 'taux_taxe']));
            $tax = $this->taxByName($tenant->id, $taxName, $taxRate);
            $rawType = strtolower((string) ($this->rowValue($row, ['type', 'item_type']) ?: ''));
            $type = $data['kind'] === 'services' ? 'service' : (str_contains($rawType, 'service') ? 'service' : (str_contains($rawType, 'item') || str_contains($rawType, 'supply') ? 'supply' : 'book'));
            $stock = $type === 'service' ? 9999 : (int) $this->decimalValue($this->rowValue($row, ['stock', 'stock_quantity', 'opening_stock', 'stock_ouverture']));

            $barcode = trim((string) $this->rowValue($row, ['barcode', 'custom_barcode', 'code_barres', 'code_de_barre'])) ?: null;
            $isbn = trim((string) $this->rowValue($row, ['isbn'])) ?: null;
            $priceBeforeTax = $this->decimalValue($this->rowValue($row, ['price_before_tax', 'price', 'prix', 'prix_ht']));
            $purchasePrice = $this->decimalValue($this->rowValue($row, ['purchase_price', 'prix_achat']) ?? $priceBeforeTax);
            $salePrice = $this->decimalValue($this->rowValue($row, ['sale_price', 'sales_price', 'prix_vente', 'prix_de_vente']) ?? $priceBeforeTax);

            $payload = [
                'tenant_id' => $tenant->id,
                'category_id' => $category->id,
                'brand_id' => $brand?->id,
                'unit_id' => $unit?->id,
                'tax_id' => $tax?->id,
                'type' => in_array($type, ['book', 'supply', 'service'], true) ? $type : 'book',
                'status' => $this->itemStatus($row, $stock, $type),
                'item_code' => trim((string) $this->rowValue($row, ['item_code', 'code_article'])) ?: $this->nextItemCode($tenant->id),
                'title' => $title,
                'isbn' => $isbn,
                'barcode' => $barcode,
                'sku' => $this->rowValue($row, ['sku']),
                'custom_barcode1' => $this->rowValue($row, ['lot_number', 'lot', 'autre_code_article']),
                'sac' => $this->rowValue($row, ['sac']),
                'hsn' => $this->rowValue($row, ['hsn']),
                'author' => $this->rowValue($row, ['author', 'auteur']),
                'editor' => $this->rowValue($row, ['editor', 'editeur_texte']),
                'edition_year' => $this->rowValue($row, ['edition_year', 'annee_edition']),
                'theme' => $this->rowValue($row, ['theme']),
                'description' => $this->rowValue($row, ['description', 'item_desciption', 'item_description']),
                'price' => $priceBeforeTax,
                'purchase_price' => $purchasePrice,
                'sale_price' => $salePrice,
                'tax_type' => $this->rowValue($row, ['tax_type', 'type_taxe']) ?: 'Exclusive',
                'discount_type' => $this->rowValue($row, ['discount_type', 'type_remise']) ?: 'Percentage',
                'discount' => (float) ($this->rowValue($row, ['discount', 'remise']) ?? 0),
                'mrp' => (float) ($this->rowValue($row, ['mrp']) ?? 0),
                'seller_points' => (float) ($this->rowValue($row, ['seller_points', 'points_vendeur']) ?? 0),
                'opening_stock' => $stock,
                'stock_quantity' => $stock,
                'min_stock_threshold' => (int) ($this->rowValue($row, ['min_stock_threshold', 'seuil_stock', 'alert_qty', 'quantite_d_alerte']) ?? 3),
                'location' => $this->rowValue($row, ['location', 'emplacement']),
            ];

            if ($barcode || $isbn) {
                $match = ['tenant_id' => $tenant->id];
                $barcode ? $match['barcode'] = $barcode : $match['isbn'] = $isbn;
                $model = Item::updateOrCreate($match, $payload);
                $model->wasRecentlyCreated ? $created++ : $updated++;
            } else {
                Item::create($payload);
                $created++;
            }
        }

        return back()->with('status', "{$created} créée(s), {$updated} mise(s) à jour, {$skipped} ignorée(s).");
    }

    public function labels(Request $request): View
    {
        $tenant = $this->tenant();
        $ids = collect(explode(',', (string) $request->query('items')))
            ->filter()
            ->map(fn (string $id) => (int) $id)
            ->values();

        $items = $tenant->items()
            ->with('category')
            ->when($ids->isNotEmpty(), fn (Builder $builder) => $builder->whereIn('id', $ids))
            ->orderBy('title')
            ->take($ids->isNotEmpty() ? 200 : 40)
            ->get();

        return view('librairepro.labels', [
            'tenant' => $tenant,
            'active' => 'catalog',
            'items' => $items,
            'template' => $request->query('template', 'medium'),
        ]);
    }

    public function updateTheme(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $presets = $this->themePresets();

        if ($request->filled('preset')) {
            $preset = (string) $request->input('preset');
            abort_unless(isset($presets[$preset]), 422);

            $settings = $tenant->settings ?? [];
            $settings['theme'] = $presets[$preset];
            $settings['theme_preset'] = $preset;
            $tenant->update(['settings' => $settings]);

            return back()->with('status', $preset === 'default' ? 'Thème réinitialisé.' : 'Thème appliqué.');
        }

        $data = $request->validate([
            'primary' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'accent' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'success' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'background' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'surface_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'surface_muted' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'text' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'muted' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'border' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'font_scale' => ['required', 'numeric', 'min:0.88', 'max:1.15'],
            'density' => ['required', 'in:soft,compact,comfortable'],
            'radius' => ['required', 'in:8,12,16'],
        ]);

        $settings = $tenant->settings ?? [];
        $settings['theme'] = $data;
        $settings['theme_preset'] = 'custom';
        $tenant->update(['settings' => $settings]);

        return back()->with('status', 'Thème mis à jour.');
    }

    public function pos(Request $request): View
    {
        $tenant = $this->tenant();
        $query = trim((string) $request->query('q'));

        $items = $tenant->items()
            ->where('status', 'active')
            ->when($query, fn (Builder $builder) => $builder->where(function (Builder $builder) use ($query): void {
                $builder->where('title', 'like', "%{$query}%")
                    ->orWhere('isbn', 'like', "%{$query}%")
                    ->orWhere('barcode', 'like', "%{$query}%");
            }))
            ->orderByRaw('case when stock_quantity <= min_stock_threshold then 0 else 1 end')
            ->orderBy('title')
            ->take(12)
            ->get();

        return view('librairepro.pos', [
            'tenant' => $tenant,
            'active' => 'sales',
            'items' => $items,
            'clients' => Contact::where('tenant_id', $tenant->id)->where('kind', 'client')->orderBy('name')->take(8)->get(),
            'recentSales' => $tenant->sales()->with('contact')->latest('sold_at')->take(5)->get(),
            'query' => $query,
        ]);
    }

    public function module(string $module): View
    {
        $tenant = $this->tenant();

        $modules = [
            'sales' => ['title' => 'Ventes', 'subtitle' => 'Historique, devis, retours, livraisons et crédits client.', 'active' => 'sales'],
            'purchases' => ['title' => 'Achats', 'subtitle' => 'Commandes fournisseurs, réception de stock et planification rentrée.', 'active' => 'purchases'],
            'loans' => ['title' => 'Emprunts', 'subtitle' => 'Prêts, retours, pénalités, réservations et cartes membre.', 'active' => 'loans'],
            'contacts' => ['title' => 'Contacts', 'subtitle' => 'Clients, écoles, fournisseurs, segmentation et communication.', 'active' => 'contacts'],
            'finance' => ['title' => 'Finances', 'subtitle' => 'Avances, coupons, dépenses, balances et clôture de caisse.', 'active' => 'finance'],
            'reports' => ['title' => 'Rapports', 'subtitle' => 'Analytique ventes, inventaire, finances et bibliothèque.', 'active' => 'reports'],
            'settings' => ['title' => 'Paramètres', 'subtitle' => 'Profil librairie, utilisateurs, rôles, intégrations et sécurité.', 'active' => 'settings'],
        ];

        abort_unless(isset($modules[$module]), 404);

        return view('librairepro.module', [
            'tenant' => $tenant,
            'active' => $modules[$module]['active'],
            'module' => $module,
            'meta' => $modules[$module],
            'sales' => $tenant->sales()->with('contact')->latest('sold_at')->take(8)->get(),
            'purchases' => Purchase::where('tenant_id', $tenant->id)->with('supplier')->latest()->take(8)->get(),
            'loans' => Loan::where('tenant_id', $tenant->id)->with(['member', 'item'])->latest()->take(8)->get(),
            'contacts' => Contact::where('tenant_id', $tenant->id)->orderBy('kind')->orderBy('name')->take(12)->get(),
            'expenses' => DB::table('expenses')->where('tenant_id', $tenant->id)->latest('spent_at')->take(8)->get(),
            'auditLogs' => DB::table('audit_logs')->where('tenant_id', $tenant->id)->latest()->take(8)->get(),
        ]);
    }

    private function tenant(): Tenant
    {
        return Tenant::query()->firstOrFail();
    }

    private function themePresets(): array
    {
        return [
            'default' => [
                'primary' => '#2563EB',
                'accent' => '#0D9488',
                'success' => '#16A34A',
                'background' => '#F6F8FB',
                'surface_color' => '#FFFFFF',
                'surface_muted' => '#EEF4FF',
                'text' => '#111827',
                'muted' => '#667085',
                'border' => '#D8E1EE',
                'font_scale' => '1',
                'density' => 'comfortable',
                'radius' => '12',
            ],
            'classic' => [
                'primary' => '#4F46E5',
                'accent' => '#0EA5E9',
                'success' => '#059669',
                'background' => '#F8FAFC',
                'surface_color' => '#FFFFFF',
                'surface_muted' => '#F1F5F9',
                'text' => '#0F172A',
                'muted' => '#64748B',
                'border' => '#E2E8F0',
                'font_scale' => '1',
                'density' => 'comfortable',
                'radius' => '10',
            ],
            'graphite' => [
                'primary' => '#334155',
                'accent' => '#0F766E',
                'success' => '#16A34A',
                'background' => '#F7F7F5',
                'surface_color' => '#FFFFFF',
                'surface_muted' => '#ECEDEA',
                'text' => '#18181B',
                'muted' => '#71717A',
                'border' => '#DADDD8',
                'font_scale' => '0.98',
                'density' => 'compact',
                'radius' => '8',
            ],
        ];
    }

    private function validatedItem(Request $request, ?Item $item = null): array
    {
        $tenant = $this->tenant();
        $request->merge([
            'title' => $request->input('title', $request->input('item_name')),
            'barcode' => $request->input('barcode', $request->input('custom_barcode')),
            'isbn' => $request->input('isbn', $request->input('ISBN')),
            'author' => $request->input('author', $request->input('auteur')),
            'editor' => $request->input('editor', $request->input('editeur')),
            'verifier' => $request->input('verifier', $request->input('verificateur')),
            'translator' => $request->input('translator', $request->input('traducteur')),
            'edition_year' => $request->input('edition_year', $request->input('annee_edition')),
            'edition_number' => $request->input('edition_number', $request->input('n_edition')),
            'paper_type' => $request->input('paper_type', $request->input('nature_de_Papier')),
            'cover_type' => $request->input('cover_type', $request->input('couverture')),
            'delivery_note' => $request->input('delivery_note', $request->input('BL')),
            'invoice_reference' => $request->input('invoice_reference', $request->input('FA')),
            'location' => $request->input('location', $request->input('emplacement')),
            'sale_price' => $request->input('sale_price', $request->input('sales_price')),
            'reseller_sale_price' => $request->input('reseller_sale_price', $request->input('sales_price1')),
            'min_stock_threshold' => $request->input('min_stock_threshold', $request->input('alert_qty')),
            'opening_stock' => $request->input('opening_stock', $request->input('adjustment_qty')),
            'warehouse' => $request->input('warehouse', $request->input('warehouse_id')),
        ]);

        $data = $request->validate([
            'type' => ['required', 'in:book,supply,service'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'item_code' => [
                'nullable',
                'string',
                'max:120',
                Rule::unique('items', 'item_code')
                    ->where(fn ($query) => $query->where('tenant_id', $tenant->id))
                    ->ignore($item?->id),
            ],
            'item_group' => ['nullable', 'in:Single,Variants,Group,Pack'],
            'nb_item' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'isbn' => [
                'nullable',
                'string',
                'max:64',
                Rule::unique('items', 'isbn')
                    ->where(fn ($query) => $query->where('tenant_id', $tenant->id))
                    ->ignore($item?->id),
            ],
            'barcode' => [
                'nullable',
                'string',
                'max:120',
                Rule::unique('items', 'barcode')
                    ->where(fn ($query) => $query->where('tenant_id', $tenant->id))
                    ->ignore($item?->id),
            ],
            'sku' => ['nullable', 'string', 'max:120'],
            'custom_barcode1' => ['nullable', 'string', 'max:120'],
            'sac' => ['nullable', 'string', 'max:120'],
            'hsn' => ['nullable', 'string', 'max:120'],
            'author' => ['nullable', 'string', 'max:255'],
            'editor' => ['nullable', 'string', 'max:255'],
            'verifier' => ['nullable', 'string', 'max:255'],
            'translator' => ['nullable', 'string', 'max:255'],
            'edition_year' => ['nullable', 'string', 'max:32'],
            'edition_number' => ['nullable', 'string', 'max:64'],
            'theme' => ['nullable', 'string', 'max:255'],
            'paper_type' => ['nullable', 'string', 'max:255'],
            'cover_type' => ['nullable', 'string', 'max:255'],
            'collection' => ['nullable', 'string', 'max:255'],
            'delivery_note' => ['nullable', 'string', 'max:255'],
            'invoice_reference' => ['nullable', 'string', 'max:255'],
            'seller_points' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'category_id' => ['required', 'exists:categories,id'],
            'brand_id' => ['nullable', 'exists:brands,id'],
            'unit_id' => ['required', 'exists:units,id'],
            'tax_id' => ['required', 'exists:taxes,id'],
            'discount_type' => ['nullable', 'in:Percentage,Fixed'],
            'discount' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'tax_type' => ['nullable', 'in:Inclusive,Exclusive'],
            'profit_margin' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'purchase_price' => ['required', 'numeric', 'min:0'],
            'sale_price' => ['required', 'numeric', 'min:0'],
            'reseller_sale_price' => ['nullable', 'numeric', 'min:0'],
            'mrp' => ['nullable', 'numeric', 'min:0'],
            'warehouse' => ['nullable', 'string', 'max:255'],
            'opening_stock' => ['nullable', 'integer', 'min:0'],
            'stock_quantity' => ['required', 'integer', 'min:0'],
            'min_stock_threshold' => ['required', 'integer', 'min:0'],
            'location' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:active,archived,out_of_stock'],
            'item_image' => ['nullable', 'image', 'max:1024'],
        ]);

        if (! empty($data['category_id'])) {
            abort_unless(Category::where('tenant_id', $tenant->id)->where('id', $data['category_id'])->exists(), 403);
        }

        if (! empty($data['brand_id'])) {
            abort_unless(Brand::where('tenant_id', $tenant->id)->where('id', $data['brand_id'])->exists(), 403);
        }

        if (! empty($data['unit_id'])) {
            abort_unless(Unit::where('tenant_id', $tenant->id)->where('id', $data['unit_id'])->exists(), 403);
        }

        if (! empty($data['tax_id'])) {
            abort_unless(Tax::where('tenant_id', $tenant->id)->where('id', $data['tax_id'])->exists(), 403);
        }

        foreach (['isbn', 'barcode', 'brand_id', 'category_id', 'unit_id', 'tax_id', 'sku', 'custom_barcode1', 'sac', 'hsn'] as $nullable) {
            $data[$nullable] = ($data[$nullable] ?? null) ?: null;
        }

        $data['item_code'] = ($data['item_code'] ?? null) ?: $this->nextItemCode($tenant->id, $item?->id);
        $data['item_group'] = $data['item_group'] ?? 'Single';
        $data['seller_points'] = $data['seller_points'] ?? 0;
        $data['discount_type'] = $data['discount_type'] ?? 'Percentage';
        $data['discount'] = $data['discount'] ?? 0;
        $data['price'] = $data['price'] ?? $data['purchase_price'];
        $data['tax_type'] = $data['tax_type'] ?? 'Exclusive';
        $data['profit_margin'] = $data['profit_margin'] ?? 0;
        $data['reseller_sale_price'] = $data['reseller_sale_price'] ?? 0;
        $data['mrp'] = $data['mrp'] ?? 0;
        $data['opening_stock'] = $data['opening_stock'] ?? ($item?->opening_stock ?? 0);

        if ($request->hasFile('item_image')) {
            $path = $request->file('item_image')->store('catalogue/items', 'public');
            $data['images'] = array_values(array_filter(array_merge($item?->images ?? [], [$path])));
        } elseif ($item?->images) {
            $data['images'] = $item->images;
        }
        unset($data['item_image']);

        if ($data['type'] === 'service') {
            $data['stock_quantity'] = 9999;
            $data['min_stock_threshold'] = 0;
            $data['purchase_price'] = $data['purchase_price'] ?? 0;
        }

        return $data;
    }

    private function authorizeTenantItem(Item $item): void
    {
        abort_unless($item->tenant_id === $this->tenant()->id, 403);
    }

    private function uniqueSlug(string $model, int $tenantId, string $name): string
    {
        $base = Str::slug($name) ?: 'categorie';
        $slug = $base;
        $index = 2;

        while ($model::where('tenant_id', $tenantId)->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$index}";
            $index++;
        }

        return $slug;
    }

    private function categoryByName(int $tenantId, string $name): Category
    {
        $name = trim($name) ?: 'Import';

        return Category::firstOrCreate(
            ['tenant_id' => $tenantId, 'slug' => Str::slug($name)],
            ['name' => $name, 'icon' => 'archive', 'color' => '#4F46E5', 'loan_duration_days' => 14, 'daily_fine_amount' => 2],
        );
    }

    private function brandByName(int $tenantId, string $name): ?Brand
    {
        $name = trim($name);

        if ($name === '') {
            return null;
        }

        return Brand::firstOrCreate(['tenant_id' => $tenantId, 'name' => $name], ['type' => 'publisher']);
    }

    private function importCategories(Tenant $tenant, array $rows): RedirectResponse
    {
        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $name = trim((string) $this->rowValue($row, ['nom_de_categorie', 'category_name', 'categorie', 'category', 'name', 'nom']));

            if ($name === '') {
                $skipped++;
                continue;
            }

            $category = Category::updateOrCreate(
                ['tenant_id' => $tenant->id, 'slug' => Str::slug($name)],
                [
                    'name' => $name,
                    'description' => $this->rowValue($row, ['la_description', 'description']),
                    'icon' => 'archive',
                    'color' => '#4F46E5',
                    'loan_duration_days' => 14,
                    'daily_fine_amount' => 2,
                ],
            );

            $category->wasRecentlyCreated ? $created++ : $updated++;
        }

        return back()->with('status', "Catégories: {$created} créée(s), {$updated} mise(s) à jour, {$skipped} ignorée(s).");
    }

    private function importBrands(Tenant $tenant, array $rows): RedirectResponse
    {
        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $name = trim((string) $this->rowValue($row, ['marque', 'brand', 'brand_name', 'publisher', 'editeur', 'name', 'nom']));

            if ($name === '') {
                $skipped++;
                continue;
            }

            $brand = Brand::updateOrCreate(
                ['tenant_id' => $tenant->id, 'name' => $name],
                [
                    'type' => 'brand',
                    'description' => $this->rowValue($row, ['la_description', 'description']),
                ],
            );

            $brand->wasRecentlyCreated ? $created++ : $updated++;
        }

        return back()->with('status', "Marques: {$created} créée(s), {$updated} mise(s) à jour, {$skipped} ignorée(s).");
    }

    private function importVariantOptions(Tenant $tenant, array $rows): RedirectResponse
    {
        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $name = trim((string) $this->rowValue($row, ['nom_de_la_variante', 'variant_name', 'variante', 'variant', 'name', 'nom']));

            if ($name === '') {
                $skipped++;
                continue;
            }

            $variant = VariantOption::updateOrCreate(
                ['tenant_id' => $tenant->id, 'name' => $name],
                [
                    'description' => $this->rowValue($row, ['la_description', 'description']),
                    'is_active' => $this->legacyActive($this->rowValue($row, ['statut', 'status'])),
                ],
            );

            $variant->wasRecentlyCreated ? $created++ : $updated++;
        }

        return back()->with('status', "Variantes: {$created} créée(s), {$updated} mise(s) à jour, {$skipped} ignorée(s).");
    }

    private function cleanLegacyCategoryName(string $name): string
    {
        $name = preg_replace('/\[(ITEM|SERVICE)\]\s*$/i', '', trim($name)) ?: $name;

        return trim($name) ?: 'Import';
    }

    private function decimalValue(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0;
        }

        $normalized = str_replace(["\xc2\xa0", ' ', ','], ['', '', '.'], (string) $value);
        $normalized = preg_replace('/[^0-9.\-]/', '', $normalized) ?: '0';

        return (float) $normalized;
    }

    private function taxParts(string $name, mixed $rate): array
    {
        $taxName = trim(preg_replace('/\([^)]*\)/', '', $name) ?: $name) ?: 'Sans TVA';
        $taxRate = $this->decimalValue($rate);

        if ($taxRate === 0.0 && preg_match('/\(([-0-9.,\s]+)%\)/', $name, $matches)) {
            $taxRate = $this->decimalValue($matches[1]);
        }

        return [$taxName, $taxRate];
    }

    private function itemStatus(array $row, int $stock, string $type): string
    {
        $status = Str::lower((string) $this->rowValue($row, ['statut', 'status']));

        if (str_contains($status, 'inactive') || str_contains($status, 'archive')) {
            return 'archived';
        }

        return $stock <= 0 && $type !== 'service' ? 'out_of_stock' : 'active';
    }

    private function legacyActive(mixed $status): bool
    {
        $status = Str::lower(trim((string) $status));

        return $status === '' || str_contains($status, 'active');
    }

    private function typeLabel(string $type): string
    {
        return match ($type) {
            'service' => 'Service',
            'supply' => 'Produit',
            default => 'Livre',
        };
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'archived' => 'Archivé',
            'out_of_stock' => 'Rupture',
            default => 'Actif',
        };
    }

    private function rowValue(array $row, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
                return $row[$key];
            }
        }

        return null;
    }

    private function unitByName(int $tenantId, string $name): ?Unit
    {
        $name = trim($name);

        if ($name === '') {
            return null;
        }

        return Unit::firstOrCreate(['tenant_id' => $tenantId, 'name' => $name]);
    }

    private function taxByName(int $tenantId, string $name, float $rate = 0): ?Tax
    {
        $name = trim($name);

        if ($name === '') {
            return null;
        }

        return Tax::firstOrCreate(['tenant_id' => $tenantId, 'name' => $name], ['rate' => $rate]);
    }

    private function nextItemCode(int $tenantId, ?int $ignoreItemId = null): string
    {
        $prefix = 'IT'.now()->format('ym');
        $next = Item::where('tenant_id', $tenantId)
                ->when($ignoreItemId, fn (Builder $builder) => $builder->where('id', '!=', $ignoreItemId))
                ->count() + 1;

        do {
            $code = $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
            $next++;
        } while (Item::where('tenant_id', $tenantId)->where('item_code', $code)->when($ignoreItemId, fn (Builder $builder) => $builder->where('id', '!=', $ignoreItemId))->exists());

        return $code;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rowsFromUpload($file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if ($extension === 'xlsx') {
            return $this->rowsFromXlsx($file->getRealPath());
        }

        if (! in_array($extension, ['csv', 'tsv'], true)) {
            return [];
        }

        $delimiter = $extension === 'tsv' ? "\t" : ',';
        $handle = fopen($file->getRealPath(), 'r');
        $headers = [];
        $rows = [];

        while (($line = fgetcsv($handle, 0, $delimiter)) !== false) {
            if ($headers === []) {
                $headers = array_map(fn ($header) => $this->normalizeImportHeader((string) $header), $line);
                continue;
            }

            $rows[] = array_combine($headers, array_pad($line, count($headers), null));
        }

        fclose($handle);

        return array_filter($rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rowsFromXlsx(string $path): array
    {
        if (! class_exists(ZipArchive::class)) {
            return [];
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return [];
        }

        $shared = [];
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedXml !== false) {
            $xml = simplexml_load_string($sharedXml);
            foreach ($xml->si ?? [] as $si) {
                $shared[] = $this->xmlText($si);
            }
        }

        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        if ($sheetXml === false) {
            return [];
        }

        $xml = simplexml_load_string($sheetXml);
        $matrix = [];

        foreach ($xml->sheetData->row ?? [] as $row) {
            $values = [];
            foreach ($row->c ?? [] as $cell) {
                $reference = (string) $cell['r'];
                $index = $reference !== '' ? $this->columnIndexFromCellReference($reference) : count($values);
                $values[$index] = $this->xlsxCellValue($cell, $shared);
            }

            if ($values !== []) {
                $max = max(array_keys($values));
                $line = array_fill(0, $max + 1, null);
                foreach ($values as $index => $value) {
                    $line[$index] = $value;
                }
                $matrix[] = $line;
            }
        }

        $headerIndex = null;
        $headers = [];

        foreach ($matrix as $index => $line) {
            $candidate = array_map(fn ($header) => $this->normalizeImportHeader((string) $header), $line);
            $filled = count(array_filter($candidate));

            if ($filled >= 2) {
                $headerIndex = $index;
                $headers = $candidate;
                break;
            }
        }

        if ($headerIndex === null || $headers === []) {
            return [];
        }

        $matrix = array_slice($matrix, $headerIndex + 1);

        return array_values(array_filter(array_map(
            fn (array $line) => $headers ? array_combine($headers, array_pad($line, count($headers), null)) : [],
            $matrix,
        ), fn (array $row) => count(array_filter($row, fn ($value) => $value !== null && $value !== '')) > 0));
    }

    private function normalizeImportHeader(string $header): string
    {
        $header = Str::ascii(Str::lower(trim($header)));
        $header = preg_replace('/[^a-z0-9]+/i', '_', $header) ?: '';

        return trim($header, '_');
    }

    private function xmlText(\SimpleXMLElement $element): string
    {
        return dom_import_simplexml($element)?->textContent ?? '';
    }

    private function xlsxCellValue(\SimpleXMLElement $cell, array $shared): mixed
    {
        $type = (string) $cell['t'];
        $value = (string) $cell->v;

        if ($type === 's') {
            return $shared[(int) $value] ?? '';
        }

        if ($type === 'inlineStr') {
            return $this->xmlText($cell);
        }

        return $value;
    }

    private function columnIndexFromCellReference(string $reference): int
    {
        preg_match('/^([A-Z]+)/i', $reference, $matches);
        $letters = strtoupper($matches[1] ?? 'A');
        $index = 0;

        foreach (str_split($letters) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return max(0, $index - 1);
    }

    private function money(float|int|string|null $amount): string
    {
        return number_format((float) $amount, 2, ',', ' ').' DH';
    }
}
