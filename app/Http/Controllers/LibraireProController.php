<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Contact;
use App\Models\CustomerAdvance;
use App\Models\DeliveryOrder;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\AccountTransaction;
use App\Models\FinancialAccount;
use App\Models\Item;
use App\Models\ItemVariant;
use App\Models\Loan;
use App\Models\PosTicket;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\SaleReturn;
use App\Models\StockAdjustment;
use App\Models\StockTransfer;
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
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;
use Yajra\DataTables\Facades\DataTables;
use ZipArchive;

class LibraireProController extends Controller
{
    public function dashboard(): View
    {
        $tenant = $this->tenant();
        $today = Carbon::today();
        $yesterday = Carbon::yesterday();
        $weekStart = now()->subDays(6)->startOfDay();
        $monthStart = now()->startOfMonth();

        $dailyRevenue = $tenant->sales()->whereDate('sold_at', $today)->sum('total_amount');
        $yesterdayRevenue = $tenant->sales()->whereDate('sold_at', $yesterday)->sum('total_amount');
        $dailyItems = DB::table('sale_items')
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->where('sales.tenant_id', $tenant->id)
            ->whereDate('sales.sold_at', $today)
            ->sum('sale_items.quantity');

        $trendRows = $tenant->sales()
            ->selectRaw('date(sold_at) as day, sum(total_amount) as total')
            ->where('sold_at', '>=', $weekStart)
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        $salesTrend = collect(range(0, 6))->map(function (int $offset) use ($weekStart, $trendRows) {
            $day = $weekStart->copy()->addDays($offset)->toDateString();

            return (object) [
                'day' => $day,
                'total' => (float) ($trendRows[$day]->total ?? 0),
            ];
        });

        $weekRevenue = (float) $salesTrend->sum('total');
        $monthRevenue = (float) $tenant->sales()->where('sold_at', '>=', $monthStart)->sum('total_amount');
        $monthlyExpenses = (float) Expense::where('tenant_id', $tenant->id)->where('spent_at', '>=', $monthStart->toDateString())->sum('amount');
        $dailyDelta = $yesterdayRevenue > 0
            ? (($dailyRevenue - $yesterdayRevenue) / max(1, $yesterdayRevenue)) * 100
            : ($dailyRevenue > 0 ? 100 : 0);
        $stockValue = (float) $tenant->items()
            ->where('type', '!=', 'service')
            ->selectRaw('sum(stock_quantity * purchase_price) as value')
            ->value('value');
        $lowStockCount = $tenant->items()
            ->where('type', '!=', 'service')
            ->whereColumn('stock_quantity', '<=', 'min_stock_threshold')
            ->count();
        $outOfStockCount = $tenant->items()
            ->where('type', '!=', 'service')
            ->where('stock_quantity', '<=', 0)
            ->count();
        $totalPhysicalItems = max(1, $tenant->items()->where('type', '!=', 'service')->count());
        $stockHealth = max(0, round((($totalPhysicalItems - $lowStockCount) / $totalPhysicalItems) * 100));
        $paymentBreakdown = SalePayment::query()
            ->where('tenant_id', $tenant->id)
            ->whereDate('paid_at', $today)
            ->selectRaw('method, sum(amount) as total')
            ->groupBy('method')
            ->orderByDesc('total')
            ->get();

        return view('librairepro.dashboard', [
            'tenant' => $tenant,
            'active' => 'dashboard',
            'stats' => [
                ['label' => 'Chiffre du jour', 'value' => $this->money($dailyRevenue), 'tone' => $dailyDelta >= 0 ? 'success' : 'danger', 'delta' => ($dailyDelta >= 0 ? '+' : '').number_format($dailyDelta, 0, ',', ' ').'% vs hier'],
                ['label' => 'Articles vendus', 'value' => number_format((float) $dailyItems, 0, ',', ' '), 'tone' => 'info', 'delta' => 'Aujourd’hui'],
                ['label' => 'CA semaine', 'value' => $this->money($weekRevenue), 'tone' => 'primary', 'delta' => '7 derniers jours'],
                ['label' => 'Marge opérationnelle', 'value' => $this->money(max(0, $monthRevenue - $monthlyExpenses)), 'tone' => 'success', 'delta' => 'Mois courant'],
            ],
            'operations' => [
                'pending_tickets' => PosTicket::where('tenant_id', $tenant->id)->where('status', 'open')->count(),
                'pending_deliveries' => DeliveryOrder::where('tenant_id', $tenant->id)->whereIn('status', ['pending', 'preparing', 'dispatched'])->count(),
                'open_quotes' => Quotation::where('tenant_id', $tenant->id)->whereIn('status', ['draft', 'sent'])->count(),
                'draft_purchases' => Purchase::where('tenant_id', $tenant->id)->whereIn('status', ['draft', 'ordered', 'partially_received'])->count(),
            ],
            'stockSummary' => [
                'health' => $stockHealth,
                'low' => $lowStockCount,
                'out' => $outOfStockCount,
                'value' => $stockValue,
            ],
            'lowStockItems' => $tenant->items()->with('category')->where('type', '!=', 'service')->whereColumn('stock_quantity', '<=', 'min_stock_threshold')->orderBy('stock_quantity')->take(6)->get(),
            'recentSales' => $tenant->sales()->with('contact')->withCount('items')->latest('sold_at')->take(7)->get(),
            'activeLoans' => $tenant->loans()->with(['member', 'item'])->whereIn('status', ['borrowed', 'overdue'])->latest()->take(5)->get(),
            'salesTrend' => $salesTrend,
            'topItems' => DB::table('sale_items')
                ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
                ->where('sales.tenant_id', $tenant->id)
                ->where('sales.sold_at', '>=', $monthStart)
                ->selectRaw('sale_items.name, sum(sale_items.quantity) as quantity, sum(sale_items.total_price) as revenue')
                ->groupBy('sale_items.name')
                ->orderByDesc('quantity')
                ->limit(5)
                ->get(),
            'paymentBreakdown' => $paymentBreakdown,
            'recentActivity' => collect([
                ['label' => 'Ventes encaissées', 'value' => $tenant->sales()->whereDate('sold_at', $today)->count(), 'href' => route('module', ['module' => 'sales', 'section' => 'list'])],
                ['label' => 'Paiements reçus', 'value' => SalePayment::where('tenant_id', $tenant->id)->whereDate('paid_at', $today)->count(), 'href' => route('module', ['module' => 'sales', 'section' => 'payments'])],
                ['label' => 'Retours vente', 'value' => SaleReturn::where('tenant_id', $tenant->id)->whereDate('returned_at', $today)->count(), 'href' => route('module', ['module' => 'sales', 'section' => 'returns'])],
                ['label' => 'Avances clients', 'value' => CustomerAdvance::where('tenant_id', $tenant->id)->whereDate('paid_at', $today)->count(), 'href' => route('module', ['module' => 'finance', 'section' => 'advances'])],
            ]),
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
        $brand = $request->query('brand', 'all');
        $unit = $request->query('unit', 'all');
        $tax = $request->query('tax', 'all');
        $stock = $request->query('stock', 'all');
        $perPage = (int) $request->query('per_page', 25);
        $perPage = in_array($perPage, [10, 25, 50, 100], true) ? $perPage : 25;
        $sort = $request->query('sort', 'title');
        $direction = $request->query('direction') === 'desc' ? 'desc' : 'asc';
        $sorts = ['title', 'barcode', 'stock_quantity', 'min_stock_threshold', 'sale_price', 'status', 'created_at'];
        $sort = in_array($sort, $sorts, true) ? $sort : 'title';
        $referenceQuery = trim((string) $request->query('reference_q'));
        $stockAdjustments = $this->stockAdjustmentsQuery($tenant, $request)->paginate($perPage, ['*'], 'adjustments_page')->withQueryString();
        $stockTransfers = $this->stockTransfersQuery($tenant, $request)->paginate($perPage, ['*'], 'transfers_page')->withQueryString();

        $itemsQuery = $this->catalogItemsQuery($tenant, $request);
        if (in_array($panel, ['services', 'ajouter-service'], true)) {
            $type = 'service';
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
            'categoryList' => Category::where('tenant_id', $tenant->id)
                ->with(['parent'])
                ->withCount('items')
                ->when($referenceQuery !== '', fn (Builder $builder) => $builder->where(function (Builder $builder) use ($referenceQuery): void {
                    $builder->where('name', 'like', "%{$referenceQuery}%")
                        ->orWhere('description', 'like', "%{$referenceQuery}%");
                }))
                ->orderBy('name')
                ->get(),
            'brandList' => Brand::where('tenant_id', $tenant->id)
                ->withCount('items')
                ->when($referenceQuery !== '', fn (Builder $builder) => $builder->where(function (Builder $builder) use ($referenceQuery): void {
                    $builder->where('name', 'like', "%{$referenceQuery}%")
                        ->orWhere('type', 'like', "%{$referenceQuery}%")
                        ->orWhere('phone', 'like', "%{$referenceQuery}%")
                        ->orWhere('email', 'like', "%{$referenceQuery}%");
                }))
                ->orderBy('name')
                ->get(),
            'unitList' => Unit::where('tenant_id', $tenant->id)
                ->withCount('items')
                ->when($referenceQuery !== '', fn (Builder $builder) => $builder->where(function (Builder $builder) use ($referenceQuery): void {
                    $builder->where('name', 'like', "%{$referenceQuery}%")
                        ->orWhere('description', 'like', "%{$referenceQuery}%");
                }))
                ->orderBy('name')
                ->get(),
            'taxList' => Tax::where('tenant_id', $tenant->id)
                ->withCount('items')
                ->when($referenceQuery !== '', fn (Builder $builder) => $builder->where(function (Builder $builder) use ($referenceQuery): void {
                    $builder->where('name', 'like', "%{$referenceQuery}%")
                        ->orWhere('description', 'like', "%{$referenceQuery}%");
                }))
                ->orderBy('name')
                ->get(),
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
            'stockItems' => $tenant->items()
                ->where('type', '!=', 'service')
                ->orderBy('title')
                ->take(500)
                ->get(),
            'stockAdjustments' => $stockAdjustments,
            'stockTransfers' => $stockTransfers,
            'stores' => $this->storeCatalog($tenant),
            'currentStore' => $this->currentStore($tenant),
            'suggestedItemCode' => $this->nextItemCode($tenant->id),
            'stockStats' => [
                'adjustments' => StockAdjustment::where('tenant_id', $tenant->id)->count(),
                'transfers' => StockTransfer::where('tenant_id', $tenant->id)->count(),
                'adjusted_month' => StockAdjustment::where('tenant_id', $tenant->id)->whereDate('adjusted_at', '>=', now()->startOfMonth())->sum('total_quantity'),
                'transferred_month' => StockTransfer::where('tenant_id', $tenant->id)->whereDate('transferred_at', '>=', now()->startOfMonth())->sum('total_quantity'),
            ],
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
            'brandFilter' => $brand,
            'unitFilter' => $unit,
            'taxFilter' => $tax,
            'stock' => $stock,
            'sort' => $sort,
            'direction' => $direction,
            'perPage' => $perPage,
            'referenceQuery' => $referenceQuery,
        ]);
    }

    public function catalogData(Request $request): \Illuminate\Http\JsonResponse
    {
        $tenant = $this->tenant();
        $panel = $request->query('panel', 'articles');

        return DataTables::eloquent($this->catalogItemsQuery($tenant, $request))
            ->filter(function (Builder $builder) use ($request): void {
                $search = trim((string) data_get($request->input('search', []), 'value'));
                if ($search === '') {
                    return;
                }

                $builder->where(function (Builder $builder) use ($search): void {
                    $builder->where('title', 'like', "%{$search}%")
                        ->orWhere('item_code', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%")
                        ->orWhere('isbn', 'like', "%{$search}%")
                        ->orWhere('barcode', 'like', "%{$search}%")
                        ->orWhere('author', 'like', "%{$search}%")
                        ->orWhereHas('category', fn (Builder $query) => $query->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('brand', fn (Builder $query) => $query->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('unit', fn (Builder $query) => $query->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('tax', fn (Builder $query) => $query->where('name', 'like', "%{$search}%"));
                });
            })
            ->addColumn('checkbox', fn (Item $item): string => '<input class="catalog-item-check rounded border-slate-300" value="'.$item->id.'" type="checkbox">')
            ->addColumn('image', function (Item $item): string {
                $image = collect($item->images)->first();

                if ($image) {
                    return '<img src="'.e(asset('storage/'.$image)).'" alt="" class="size-11 rounded-lg object-cover">';
                }

                return '<div class="grid size-11 place-items-center rounded-lg bg-slate-100 text-xs font-bold text-slate-500 dark:bg-white/10 dark:text-slate-300">'.e(mb_substr($item->title, 0, 2)).'</div>';
            })
            ->editColumn('barcode', fn (Item $item): string => e($item->barcode ?? $item->isbn ?? $item->sku ?? '—'))
            ->editColumn('title', function (Item $item): string {
                $brand = $item->brand?->name ? ' · '.$item->brand->name : '';
                $variants = $item->variants->isNotEmpty() ? '<p class="mt-1 text-xs font-medium text-brand">'.$item->variants->count().' variante(s)</p>' : '';

                return '<div class="max-w-[320px]"><p class="font-semibold">'.e($item->title).'</p><p class="mt-1 text-xs text-slate-500">'.e($item->item_code ?? 'Sans code interne').e($brand).'</p>'.$variants.'</div>';
            })
            ->addColumn('category_type', fn (Item $item): string => '<span class="font-medium">'.e($item->category?->name ?? 'Sans catégorie').'</span><span class="mt-1 block text-xs text-slate-500">'.e($this->typeLabel($item->type)).'</span>')
            ->addColumn('unit_label', fn (Item $item): string => e($item->unit?->name ?? '—'))
            ->editColumn('stock_quantity', fn (Item $item): string => '<span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset '.($item->is_low_stock && $item->type !== 'service' ? 'bg-amber-50 text-amber-700 ring-amber-200' : 'bg-emerald-50 text-emerald-700 ring-emerald-200').'">'.($item->type === 'service' ? 'Illimité' : number_format($item->stock_quantity, 0, ',', ' ')).'</span>')
            ->editColumn('min_stock_threshold', fn (Item $item): string => $item->type === 'service' ? '—' : number_format($item->min_stock_threshold, 0, ',', ' '))
            ->editColumn('sale_price', fn (Item $item): string => '<strong>'.$this->money($item->sale_price).'</strong>')
            ->addColumn('tax_label', fn (Item $item): string => e($item->tax ? $item->tax->name.' ('.number_format((float) $item->tax->rate, 2, ',', ' ').'%)' : '—'))
            ->editColumn('status', function (Item $item): string {
                $tone = match ($item->status) {
                    'out_of_stock' => 'bg-rose-50 text-rose-700 ring-rose-200',
                    'archived' => 'bg-slate-100 text-slate-700 ring-slate-200',
                    default => 'bg-blue-50 text-blue-700 ring-blue-200',
                };

                return '<span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset '.$tone.'">'.e($this->statusLabel($item->status)).'</span>';
            })
            ->addColumn('action', fn (Item $item): string => '<div class="flex min-w-[160px] justify-end gap-2"><a href="'.e(route('catalog', ['panel' => $panel === 'services' ? 'services' : 'articles', 'edit' => $item->id])).'#edit-item" class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold transition hover:border-brand hover:text-brand dark:border-white/10">Voir</a><a href="'.e(route('catalog', ['panel' => $panel === 'services' ? 'services' : 'articles', 'edit' => $item->id])).'#edit-item" class="rounded-lg bg-brand px-3 py-2 text-xs font-semibold text-white">Modifier</a></div>')
            ->rawColumns(['checkbox', 'image', 'title', 'category_type', 'stock_quantity', 'sale_price', 'status', 'action'])
            ->toJson();
    }

    public function storeStockAdjustment(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $data = $request->validate([
            'adjusted_at' => ['nullable', 'date'],
            'warehouse' => ['nullable', 'string', 'max:120'],
            'reason' => ['nullable', 'string', 'max:160'],
            'note' => ['nullable', 'string', 'max:700'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['nullable', 'integer', Rule::exists('items', 'id')->where('tenant_id', $tenant->id)],
            'items.*.direction' => ['nullable', 'in:add,remove,set'],
            'items.*.quantity' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'items.*.note' => ['nullable', 'string', 'max:300'],
        ]);

        try {
            $adjustment = DB::transaction(function () use ($tenant, $data): StockAdjustment {
                $lines = [];
                $totalQuantity = 0;

                foreach ($data['items'] as $line) {
                    if (empty($line['item_id']) || (int) ($line['quantity'] ?? 0) <= 0) {
                        continue;
                    }

                    $item = Item::where('tenant_id', $tenant->id)
                        ->where('type', '!=', 'service')
                        ->lockForUpdate()
                        ->findOrFail((int) $line['item_id']);

                    $quantity = (int) $line['quantity'];
                    $direction = $line['direction'] ?? 'add';
                    $before = (int) $item->stock_quantity;
                    $after = match ($direction) {
                        'remove' => max(0, $before - $quantity),
                        'set' => $quantity,
                        default => $before + $quantity,
                    };
                    $delta = $after - $before;

                    $item->update([
                        'stock_quantity' => $after,
                        'status' => $after <= 0 ? 'out_of_stock' : ($item->status === 'out_of_stock' ? 'active' : $item->status),
                    ]);

                    $lines[] = [
                        'item_id' => $item->id,
                        'item_code' => $item->item_code,
                        'name' => $item->title,
                        'barcode' => $item->barcode,
                        'direction' => $direction,
                        'quantity' => $quantity,
                        'quantity_before' => $before,
                        'quantity_after' => $after,
                        'quantity_delta' => $delta,
                        'note' => $line['note'] ?? null,
                    ];
                    $totalQuantity += abs($delta);
                }

                if ($lines === []) {
                    throw new \RuntimeException('Ajoutez au moins une ligne avec une quantité positive.');
                }

                $adjustment = StockAdjustment::create([
                    'tenant_id' => $tenant->id,
                    'number' => $this->nextStockAdjustmentNumber($tenant),
                    'status' => 'completed',
                    'warehouse' => $data['warehouse'] ?? null,
                    'reason' => $data['reason'] ?? null,
                    'total_quantity' => $totalQuantity,
                    'lines' => $lines,
                    'note' => $data['note'] ?? null,
                    'adjusted_at' => $data['adjusted_at'] ?? now(),
                ]);

                foreach ($lines as $line) {
                    DB::table('stock_movements')->insert([
                        'tenant_id' => $tenant->id,
                        'item_id' => $line['item_id'],
                        'user_id' => null,
                        'type' => 'adjustment',
                        'quantity_delta' => $line['quantity_delta'],
                        'quantity_after' => $line['quantity_after'],
                        'reference_type' => StockAdjustment::class,
                        'reference_id' => $adjustment->id,
                        'note' => trim(($data['reason'] ?? 'Ajustement stock').' '.($line['note'] ?? '')),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                return $adjustment;
            });
        } catch (\RuntimeException $exception) {
            return back()->withInput()->withErrors(['stock' => $exception->getMessage()]);
        }

        return redirect()
            ->route('catalog', ['panel' => 'stock-adjustments'])
            ->with('status', 'Ajustement '.$adjustment->number.' enregistré.');
    }

    public function storeStockTransfer(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $data = $request->validate([
            'transferred_at' => ['nullable', 'date'],
            'store_from' => ['nullable', 'string', 'max:120'],
            'warehouse_from' => ['nullable', 'string', 'max:120'],
            'store_to' => ['nullable', 'string', 'max:120'],
            'warehouse_to' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:700'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['nullable', 'integer', Rule::exists('items', 'id')->where('tenant_id', $tenant->id)],
            'items.*.quantity' => ['nullable', 'integer', 'min:1', 'max:999999'],
            'items.*.note' => ['nullable', 'string', 'max:300'],
        ]);

        try {
            $transfer = DB::transaction(function () use ($tenant, $data): StockTransfer {
                $lines = [];
                $totalQuantity = 0;

                foreach ($data['items'] as $line) {
                    if (empty($line['item_id']) || (int) ($line['quantity'] ?? 0) <= 0) {
                        continue;
                    }

                    $item = Item::where('tenant_id', $tenant->id)
                        ->where('type', '!=', 'service')
                        ->lockForUpdate()
                        ->findOrFail((int) $line['item_id']);

                    $quantity = (int) $line['quantity'];
                    if ((int) $item->stock_quantity < $quantity) {
                        throw new \RuntimeException('Stock insuffisant pour '.$item->title.'. Disponible: '.$item->stock_quantity.'.');
                    }

                    $lines[] = [
                        'item_id' => $item->id,
                        'item_code' => $item->item_code,
                        'name' => $item->title,
                        'barcode' => $item->barcode,
                        'quantity' => $quantity,
                        'available_stock' => (int) $item->stock_quantity,
                        'note' => $line['note'] ?? null,
                    ];
                    $totalQuantity += $quantity;
                }

                if ($lines === []) {
                    throw new \RuntimeException('Ajoutez au moins une ligne de transfert.');
                }

                $transfer = StockTransfer::create([
                    'tenant_id' => $tenant->id,
                    'number' => $this->nextStockTransferNumber($tenant),
                    'status' => 'completed',
                    'store_from' => $data['store_from'] ?? null,
                    'warehouse_from' => $data['warehouse_from'] ?? null,
                    'store_to' => $data['store_to'] ?? null,
                    'warehouse_to' => $data['warehouse_to'] ?? null,
                    'total_quantity' => $totalQuantity,
                    'lines' => $lines,
                    'note' => $data['note'] ?? null,
                    'transferred_at' => $data['transferred_at'] ?? now(),
                ]);

                foreach ($lines as $line) {
                    DB::table('stock_movements')->insert([
                        'tenant_id' => $tenant->id,
                        'item_id' => $line['item_id'],
                        'user_id' => null,
                        'type' => 'transfer',
                        'quantity_delta' => 0,
                        'quantity_after' => $line['available_stock'],
                        'reference_type' => StockTransfer::class,
                        'reference_id' => $transfer->id,
                        'note' => 'Transfert stock '.$transfer->number,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                return $transfer;
            });
        } catch (\RuntimeException $exception) {
            return back()->withInput()->withErrors(['stock' => $exception->getMessage()]);
        }

        return redirect()
            ->route('catalog', ['panel' => 'stock-transfers'])
            ->with('status', 'Transfert '.$transfer->number.' enregistré.');
    }

    public function contactsData(Request $request): \Illuminate\Http\JsonResponse
    {
        $tenant = $this->tenant();
        $kind = in_array($request->query('kind'), ['client', 'supplier'], true) ? $request->query('kind') : 'client';

        $dataTable = DataTables::eloquent($this->contactsQuery($tenant, $request, $kind))
            ->filter(function (Builder $builder) use ($request): void {
                $search = trim((string) data_get($request->input('search', []), 'value'));
                if ($search === '') {
                    return;
                }

                $builder->where(function (Builder $builder) use ($search): void {
                    $builder->where('code', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('city', 'like', "%{$search}%")
                        ->orWhere('address', 'like', "%{$search}%")
                        ->orWhere('tax_number', 'like', "%{$search}%")
                        ->orWhere('ice', 'like', "%{$search}%");
                });
            })
            ->addColumn('checkbox', fn (Contact $contact): string => '<input class="contact-check rounded border-slate-300" value="'.$contact->id.'" type="checkbox">')
            ->editColumn('code', fn (Contact $contact): string => e($contact->code ?? '—'))
            ->editColumn('name', function (Contact $contact): string {
                $tags = collect($contact->tags)->filter()->take(2)->map(fn ($tag) => '<span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-500 dark:bg-white/10">'.e($tag).'</span>')->implode(' ');

                return '<div class="min-w-48"><p class="font-semibold">'.e($contact->name).'</p><p class="mt-1 text-xs text-slate-500">'.e($contact->client_type ?? ($contact->kind === 'supplier' ? 'Fournisseur' : 'Particulier')).'</p><div class="mt-1 flex gap-1">'.$tags.'</div></div>';
            })
            ->addColumn('mobile', fn (Contact $contact): string => e($contact->phone ?? '—'))
            ->editColumn('email', fn (Contact $contact): string => e($contact->email ?? '—'))
            ->addColumn('location', fn (Contact $contact): string => e(collect([$contact->city, $contact->state, $contact->country])->filter()->implode(', ') ?: ($contact->address ?? '—')))
            ->editColumn('credit_limit', fn (Contact $contact): string => '<span class="font-semibold">'.$this->money($contact->credit_limit).'</span>')
            ->addColumn('previous_balance', fn (Contact $contact): string => '<span class="font-semibold">'.$this->money($contact->opening_balance).'</span>')
            ->addColumn('purchase_due', fn (Contact $contact): string => '<span class="font-semibold text-rose-600">'.$this->money((float) ($contact->purchases_due_sum ?? 0)).'</span>')
            ->addColumn('purchase_return_due', fn (Contact $contact): string => '<span class="font-semibold text-emerald-600">'.$this->money((float) ($contact->purchase_returns_due_sum ?? 0)).'</span>')
            ->addColumn('supplier_total', fn (Contact $contact): string => '<span class="font-semibold">'.$this->money((float) $contact->opening_balance + (float) ($contact->purchases_due_sum ?? 0) - (float) ($contact->purchase_returns_due_sum ?? 0)).'</span>')
            ->editColumn('outstanding_balance', fn (Contact $contact): string => '<span class="'.((float) $contact->outstanding_balance > 0 ? 'font-semibold text-rose-600' : 'text-slate-500').'">'.$this->money($contact->outstanding_balance).'</span>')
            ->editColumn('advance_balance', fn (Contact $contact): string => '<span class="'.((float) $contact->advance_balance > 0 ? 'font-semibold text-emerald-600' : 'text-slate-500').'">'.$this->money($contact->advance_balance).'</span>')
            ->editColumn('status', fn (Contact $contact): string => '<span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset '.($contact->status === 'active' ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-slate-100 text-slate-700 ring-slate-200').'">'.e($contact->status === 'active' ? 'Actif' : 'Archivé').'</span>')
            ->addColumn('action', function (Contact $contact): string {
                $editUrl = route('module', ['module' => 'contacts', 'section' => $contact->kind === 'supplier' ? 'supplier-add' : 'customer-add', 'edit' => $contact->id]);
                $deleteUrl = route('contacts.destroy', $contact);

                return '<div class="flex justify-end gap-2"><a href="'.e($editUrl).'#contact-form" class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold dark:border-white/10">Modifier</a><form action="'.e($deleteUrl).'" method="POST" onsubmit="return confirm(\'Supprimer ou archiver ce contact ?\')"><input type="hidden" name="_token" value="'.e(csrf_token()).'"><input type="hidden" name="_method" value="DELETE"><button class="rounded-lg border border-rose-200 px-3 py-2 text-xs font-semibold text-rose-600 dark:border-rose-500/20" type="submit">Supprimer</button></form></div>';
            })
            ->rawColumns(['checkbox', 'name', 'credit_limit', 'previous_balance', 'purchase_due', 'purchase_return_due', 'supplier_total', 'outstanding_balance', 'advance_balance', 'status', 'action']);

        return $dataTable->toJson();
    }

    public function customerAdvancesData(Request $request): \Illuminate\Http\JsonResponse
    {
        $tenant = $this->tenant();

        return DataTables::eloquent($this->customerAdvancesQuery($tenant, $request))
            ->filter(function (Builder $builder) use ($request): void {
                $search = trim((string) data_get($request->input('search', []), 'value'));
                if ($search === '') {
                    return;
                }

                $builder->where(function (Builder $builder) use ($search): void {
                    $builder->where('number', 'like', "%{$search}%")
                        ->orWhere('payment_method', 'like', "%{$search}%")
                        ->orWhere('reference', 'like', "%{$search}%")
                        ->orWhere('note', 'like', "%{$search}%")
                        ->orWhereHas('contact', function (Builder $contactQuery) use ($search): void {
                            $contactQuery->where('name', 'like', "%{$search}%")
                                ->orWhere('code', 'like', "%{$search}%")
                                ->orWhere('phone', 'like', "%{$search}%");
                        });
                });
            })
            ->addColumn('checkbox', fn (CustomerAdvance $advance): string => '<input class="advance-check rounded border-slate-300" value="'.$advance->id.'" type="checkbox">')
            ->editColumn('paid_at', fn (CustomerAdvance $advance): string => $advance->paid_at?->format('d/m/Y H:i') ?? '—')
            ->editColumn('number', fn (CustomerAdvance $advance): string => '<span class="font-semibold">'.e($advance->number).'</span>')
            ->addColumn('customer', function (CustomerAdvance $advance): string {
                $contact = $advance->contact;

                return '<div class="min-w-48"><p class="font-semibold">'.e($contact?->name ?? 'Client supprimé').'</p><p class="mt-1 text-xs text-slate-500">'.e($contact?->code ?? '—').' · '.e($contact?->phone ?? 'Sans mobile').'</p></div>';
            })
            ->addColumn('mobile', fn (CustomerAdvance $advance): string => e($advance->contact?->phone ?? '—'))
            ->editColumn('payment_method', fn (CustomerAdvance $advance): string => '<span class="inline-flex rounded-full bg-blue-50 px-2.5 py-1 text-xs font-semibold text-blue-700 ring-1 ring-blue-200">'.e($this->paymentMethodLabel($advance->payment_method)).'</span>')
            ->editColumn('reference', fn (CustomerAdvance $advance): string => e($advance->reference ?? '—'))
            ->editColumn('amount', fn (CustomerAdvance $advance): string => '<span class="'.($advance->status === 'active' ? 'font-semibold text-emerald-600' : 'font-semibold text-slate-400').'">'.$this->money($advance->amount).'</span>')
            ->editColumn('status', fn (CustomerAdvance $advance): string => '<span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset '.($advance->status === 'active' ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-slate-100 text-slate-600 ring-slate-200').'">'.e($advance->status === 'active' ? 'Active' : 'Annulée').'</span>')
            ->addColumn('action', function (CustomerAdvance $advance): string {
                $deleteUrl = route('customer-advances.destroy', $advance);
                $details = e(json_encode([
                    'number' => $advance->number,
                    'client' => $advance->contact?->name ?? 'Client supprimé',
                    'mobile' => $advance->contact?->phone ?? '—',
                    'amount' => $this->money($advance->amount),
                    'payment' => $this->paymentMethodLabel($advance->payment_method),
                    'reference' => $advance->reference ?? '—',
                    'date' => $advance->paid_at?->format('d/m/Y H:i') ?? '—',
                    'note' => $advance->note ?? '—',
                    'status' => $advance->status === 'active' ? 'Active' : 'Annulée',
                ], JSON_THROW_ON_ERROR));
                $cancel = $advance->status === 'active'
                    ? '<form action="'.e($deleteUrl).'" method="POST" onsubmit="return confirm(\'Annuler cette avance et ajuster le solde client ?\')"><input type="hidden" name="_token" value="'.e(csrf_token()).'"><input type="hidden" name="_method" value="DELETE"><button class="rounded-lg border border-rose-200 px-3 py-2 text-xs font-semibold text-rose-600 dark:border-rose-500/20" type="submit">Annuler</button></form>'
                    : '';

                return '<div class="flex justify-end gap-2"><button type="button" data-advance-detail="'.$details.'" class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold dark:border-white/10">Reçu</button>'.$cancel.'</div>';
            })
            ->rawColumns(['checkbox', 'number', 'customer', 'payment_method', 'amount', 'status', 'action'])
            ->toJson();
    }

    public function storeContact(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $data = $this->validateContact($request, $tenant);
        $contact = Contact::create($this->contactPayload($request, $tenant, $data));

        return redirect()
            ->route('module', ['module' => 'contacts', 'section' => $contact->kind === 'supplier' ? 'suppliers' : 'customers'])
            ->with('status', ($contact->kind === 'supplier' ? 'Fournisseur ' : 'Client ').$contact->name.' ajouté.');
    }

    public function updateContact(Request $request, Contact $contact): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless($contact->tenant_id === $tenant->id, 404);

        $data = $this->validateContact($request, $tenant, $contact);
        $contact->update($this->contactPayload($request, $tenant, $data, $contact));

        return redirect()
            ->route('module', ['module' => 'contacts', 'section' => $contact->kind === 'supplier' ? 'suppliers' : 'customers'])
            ->with('status', ($contact->kind === 'supplier' ? 'Fournisseur ' : 'Client ').$contact->name.' mis à jour.');
    }

    public function destroyContact(Contact $contact): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless($contact->tenant_id === $tenant->id, 404);

        if ($contact->sales()->exists() || $contact->loans()->exists() || Purchase::where('tenant_id', $tenant->id)->where('supplier_id', $contact->id)->exists()) {
            $contact->update(['status' => 'archived']);

            return back()->with('status', 'Contact archivé car il possède déjà un historique.');
        }

        $contact->delete();

        return back()->with('status', 'Contact supprimé.');
    }

    public function exportCatalog(Request $request): StreamedResponse
    {
        $tenant = $this->tenant();
        $panel = $request->query('panel', 'articles');

        $itemsRequest = $request;
        if ($request->boolean('all')) {
            $itemsRequest = $request->duplicate(array_merge($request->query(), [
                'panel' => 'all',
                'q' => '',
                'status' => 'all',
                'type' => 'all',
                'category' => 'all',
                'brand' => 'all',
                'unit' => 'all',
                'tax' => 'all',
                'stock' => 'all',
                'min_price' => '',
                'max_price' => '',
            ]));
        }

        $items = $this->catalogItemsQuery($tenant, $itemsRequest)
            ->orderBy('title')
            ->get();

        $filename = 'catalogue-'.($request->boolean('all') ? 'complet' : ($panel === 'services' ? 'services' : 'articles')).'-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($items, $panel): void {
            $handle = fopen('php://output', 'w');
            $headers = ['Image', 'Code de barre', "Nom de l'article", "Catégorie/Type d'élément", 'Marque / éditeur', 'Unité', 'Stock'];
            if ($panel !== 'services') {
                $headers[] = "Quantité d'alerte";
            }
            array_push($headers, 'Prix de vente', 'Impôt', 'Statut', 'Action');
            fputcsv($handle, $headers);

            foreach ($items as $item) {
                $row = [
                    collect($item->images)->first() ?? '',
                    $item->barcode ?? $item->isbn ?? $item->sku ?? '',
                    $item->title,
                    ($item->category?->name ?? 'Sans catégorie').' / '.$this->typeLabel($item->type),
                    $item->brand?->name ?? '',
                    $item->unit?->name ?? '',
                    $item->type === 'service' ? 'Illimité' : $item->stock_quantity,
                ];
                if ($panel !== 'services') {
                    $row[] = $item->min_stock_threshold;
                }
                array_push($row,
                    $item->sale_price,
                    $item->tax ? $item->tax->name.' ('.number_format((float) $item->tax->rate, 2, '.', '').'%)' : '',
                    $this->statusLabel($item->status),
                    route('catalog', ['panel' => $item->type === 'service' ? 'services' : 'articles', 'edit' => $item->id])
                );
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function catalogItemsQuery(Tenant $tenant, Request $request): Builder
    {
        $panel = $request->query('panel', 'articles');
        $query = trim((string) $request->query('q'));
        $status = $request->query('status', 'all');
        $type = $request->query('type', 'all');
        $category = $request->query('category', 'all');
        $brand = $request->query('brand', 'all');
        $unit = $request->query('unit', 'all');
        $tax = $request->query('tax', 'all');
        $stock = $request->query('stock', 'all');
        $minPrice = $request->query('min_price');
        $maxPrice = $request->query('max_price');

        return Item::query()
            ->where('tenant_id', $tenant->id)
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
            ->when($brand !== 'all', fn (Builder $builder) => $builder->where('brand_id', $brand))
            ->when($unit !== 'all', fn (Builder $builder) => $builder->where('unit_id', $unit))
            ->when($tax !== 'all', fn (Builder $builder) => $builder->where('tax_id', $tax))
            ->when($stock === 'low', fn (Builder $builder) => $builder->whereColumn('stock_quantity', '<=', 'min_stock_threshold'))
            ->when($stock === 'out', fn (Builder $builder) => $builder->where('stock_quantity', '<=', 0))
            ->when(is_numeric($minPrice), fn (Builder $builder) => $builder->where('sale_price', '>=', (float) $minPrice))
            ->when(is_numeric($maxPrice), fn (Builder $builder) => $builder->where('sale_price', '<=', (float) $maxPrice))
            ->when(in_array($panel, ['services', 'ajouter-service'], true), fn (Builder $builder) => $builder->where('type', 'service'))
            ->when(! in_array($panel, ['services', 'ajouter-service', 'all'], true) && $type !== 'all', fn (Builder $builder) => $builder->where('type', $type))
            ->when(! in_array($panel, ['services', 'ajouter-service', 'all'], true) && $type === 'all', fn (Builder $builder) => $builder->where('type', '!=', 'service'));
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
            'name' => ['required', 'string', 'max:255', Rule::unique('categories')->where('tenant_id', $tenant->id)],
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

    public function updateCategory(Request $request, Category $category): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless((int) $category->tenant_id === (int) $tenant->id, 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('categories')->where('tenant_id', $tenant->id)->ignore($category->id)],
            'parent_id' => ['nullable', 'exists:categories,id'],
            'icon' => ['nullable', 'string', 'max:64'],
            'color' => ['nullable', 'string', 'max:16'],
            'description' => ['nullable', 'string', 'max:2000'],
            'loan_duration_days' => ['required', 'integer', 'min:1', 'max:365'],
            'daily_fine_amount' => ['required', 'numeric', 'min:0', 'max:9999'],
        ]);

        if (! empty($data['parent_id'])) {
            abort_unless((int) $data['parent_id'] !== (int) $category->id, 422);
            abort_unless(Category::where('tenant_id', $tenant->id)->where('id', $data['parent_id'])->exists(), 403);
        }

        if ($category->name !== $data['name']) {
            $data['slug'] = $this->uniqueSlug(Category::class, $tenant->id, $data['name']);
        }

        $data['icon'] = ($data['icon'] ?? null) ?: 'book-open';
        $data['color'] = ($data['color'] ?? null) ?: '#4F46E5';
        $category->update($data);

        return back()->with('status', 'Catégorie mise à jour.');
    }

    public function destroyCategory(Category $category): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless((int) $category->tenant_id === (int) $tenant->id, 403);

        if ($category->items()->exists() || $category->children()->exists()) {
            return back()->withErrors(['reference' => 'Cette catégorie est utilisée par des articles ou des sous-catégories. Modifiez les liaisons avant suppression.']);
        }

        $category->delete();

        return back()->with('status', 'Catégorie supprimée.');
    }

    public function storeBrand(Request $request): RedirectResponse|\Illuminate\Http\JsonResponse
    {
        $tenant = $this->tenant();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('brands')->where('tenant_id', $tenant->id)],
            'type' => ['required', 'in:publisher,brand'],
            'description' => ['nullable', 'string', 'max:2000'],
            'phone' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
        ]);

        $data['tenant_id'] = $tenant->id;
        $brand = Brand::create($data);

        if ($request->expectsJson()) {
            return response()->json(['id' => $brand->id, 'label' => $brand->name]);
        }

        return back()->with('status', 'Marque ou éditeur ajouté.');
    }

    public function updateBrand(Request $request, Brand $brand): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless((int) $brand->tenant_id === (int) $tenant->id, 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('brands')->where('tenant_id', $tenant->id)->ignore($brand->id)],
            'type' => ['required', 'in:publisher,brand'],
            'description' => ['nullable', 'string', 'max:2000'],
            'phone' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
        ]);

        $brand->update($data);

        return back()->with('status', 'Marque ou éditeur mis à jour.');
    }

    public function destroyBrand(Brand $brand): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless((int) $brand->tenant_id === (int) $tenant->id, 403);

        if ($brand->items()->exists()) {
            return back()->withErrors(['reference' => 'Cette marque / éditeur est utilisée par des articles. Modifiez les articles avant suppression.']);
        }

        $brand->delete();

        return back()->with('status', 'Marque ou éditeur supprimé.');
    }

    public function storeUnit(Request $request): RedirectResponse|\Illuminate\Http\JsonResponse
    {
        $tenant = $this->tenant();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('units')->where('tenant_id', $tenant->id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $data['is_active'] = $request->boolean('is_active', true);

        $unit = Unit::firstOrCreate(
            ['tenant_id' => $tenant->id, 'name' => $data['name']],
            ['description' => $data['description'] ?? null],
        );

        if ($request->expectsJson()) {
            return response()->json(['id' => $unit->id, 'label' => $unit->name]);
        }

        return back()->with('status', 'Unité ajoutée.');
    }

    public function updateUnit(Request $request, Unit $unit): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless((int) $unit->tenant_id === (int) $tenant->id, 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('units')->where('tenant_id', $tenant->id)->ignore($unit->id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $data['is_active'] = $request->boolean('is_active');

        $unit->update($data);

        return back()->with('status', 'Unité mise à jour.');
    }

    public function destroyUnit(Unit $unit): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless((int) $unit->tenant_id === (int) $tenant->id, 403);

        if ($unit->items()->exists()) {
            return back()->withErrors(['reference' => 'Cette unité est utilisée par des articles. Modifiez les articles avant suppression.']);
        }

        $unit->delete();

        return back()->with('status', 'Unité supprimée.');
    }

    public function storeTax(Request $request): RedirectResponse|\Illuminate\Http\JsonResponse
    {
        $tenant = $this->tenant();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('taxes')->where('tenant_id', $tenant->id)],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $data['is_active'] = $request->boolean('is_active', true);

        $tax = Tax::firstOrCreate(
            ['tenant_id' => $tenant->id, 'name' => $data['name']],
            ['rate' => $data['rate'], 'description' => $data['description'] ?? null],
        );

        if ($request->expectsJson()) {
            return response()->json(['id' => $tax->id, 'label' => $tax->name.' ('.number_format((float) $tax->rate, 2, ',', ' ').'%)']);
        }

        return back()->with('status', 'Taxe ajoutée.');
    }

    public function updateTax(Request $request, Tax $tax): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless((int) $tax->tenant_id === (int) $tenant->id, 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('taxes')->where('tenant_id', $tenant->id)->ignore($tax->id)],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $data['is_active'] = $request->boolean('is_active');

        $tax->update($data);

        return back()->with('status', 'Impôt mis à jour.');
    }

    public function destroyTax(Tax $tax): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless((int) $tax->tenant_id === (int) $tenant->id, 403);

        if ($tax->items()->exists()) {
            return back()->withErrors(['reference' => 'Cet impôt est utilisé par des articles. Modifiez les articles avant suppression.']);
        }

        $tax->delete();

        return back()->with('status', 'Impôt supprimé.');
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

    public function importExample(string $kind)
    {
        abort_unless(in_array($kind, ['items', 'services', 'categories', 'brands', 'variants'], true), 404);

        $example = $this->importExampleRows($kind);
        $path = $this->buildSimpleXlsx($example['title'], $example['headers'], $example['rows']);

        return response()
            ->download($path, $example['filename'], [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend(true);
    }

    public function labels(Request $request): View
    {
        $tenant = $this->tenant();
        $selectedFromCsv = collect(explode(',', (string) $request->query('items')))
            ->filter()
            ->map(fn (string $id) => (int) $id)
            ->values();
        $selectedFromForm = collect($request->query('selected_items', []))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->values();
        $ids = $selectedFromForm->isNotEmpty() ? $selectedFromForm : $selectedFromCsv;
        $template = in_array($request->query('template'), ['small', 'medium', 'large'], true) ? $request->query('template') : 'medium';
        $query = trim((string) $request->query('q'));
        $category = $request->query('category');
        $type = $request->query('type', 'all');
        $quantities = collect($request->query('quantities', []))->mapWithKeys(fn ($quantity, $id) => [(int) $id => min(100, max(1, (int) $quantity))]);
        $defaultCopies = min(100, max(1, (int) $request->query('copies', 1)));
        $searchTokens = Str::of($query)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->explode(' ')
            ->filter()
            ->take(6)
            ->values();

        $productOptions = $tenant->items()
            ->with(['category', 'brand'])
            ->where('status', 'active')
            ->when($query !== '', function (Builder $builder) use ($query, $searchTokens): void {
                $fields = ['title', 'item_code', 'barcode', 'isbn', 'sku', 'custom_barcode1', 'author', 'editor', 'description'];
                $likeField = function (Builder $builder, string $term) use ($fields): void {
                    foreach ($fields as $index => $field) {
                        $method = $index === 0 ? 'where' : 'orWhere';
                        $builder->{$method}($field, 'like', "%{$term}%");
                    }

                    $builder
                        ->orWhereHas('category', fn (Builder $categoryQuery) => $categoryQuery->where('name', 'like', "%{$term}%"))
                        ->orWhereHas('brand', fn (Builder $brandQuery) => $brandQuery->where('name', 'like', "%{$term}%"));
                };

                $builder->where(function (Builder $builder) use ($query, $searchTokens, $likeField): void {
                    $builder->where(function (Builder $builder) use ($query, $likeField): void {
                        $likeField($builder, $query);
                    });

                    if ($searchTokens->isNotEmpty()) {
                        $builder->orWhere(function (Builder $builder) use ($searchTokens, $likeField): void {
                            foreach ($searchTokens as $token) {
                                $builder->where(function (Builder $builder) use ($token, $likeField): void {
                                    $likeField($builder, (string) $token);
                                });
                            }
                        });
                    }
                });
            })
            ->when($category, fn (Builder $builder) => $builder->where('category_id', $category))
            ->when(in_array($type, ['book', 'supply', 'service'], true), fn (Builder $builder) => $builder->where('type', $type))
            ->orderBy('title')
            ->take(80)
            ->get();

        if ($ids->isNotEmpty()) {
            $selectedOptions = $tenant->items()
                ->with(['category', 'brand'])
                ->whereIn('id', $ids)
                ->orderBy('title')
                ->get();
            $productOptions = $selectedOptions
                ->merge($productOptions->reject(fn (Item $item) => $ids->contains($item->id)))
                ->values();
        }

        $items = $tenant->items()
            ->with(['category', 'brand'])
            ->when($ids->isNotEmpty(), fn (Builder $builder) => $builder->whereIn('id', $ids))
            ->orderBy('title')
            ->take($ids->isNotEmpty() ? 200 : 0)
            ->get();

        return view('librairepro.labels', [
            'tenant' => $tenant,
            'active' => 'catalog',
            'items' => $items,
            'productOptions' => $productOptions,
            'categories' => Category::where('tenant_id', $tenant->id)->orderBy('name')->get(),
            'template' => $template,
            'selectedIds' => $ids,
            'quantities' => $quantities,
            'defaultCopies' => $defaultCopies,
            'query' => $query,
            'categoryFilter' => $category,
            'type' => $type,
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

    public function updateCompanyProfile(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();

        $data = $request->validate([
            'store_code' => ['nullable', 'string', 'max:60'],
            'store_name' => ['required', 'string', 'max:160'],
            'mobile' => ['nullable', 'string', 'max:60'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:60'],
            'cnss' => ['nullable', 'string', 'max:80'],
            'rc' => ['nullable', 'string', 'max:80'],
            'gst_no' => ['nullable', 'string', 'max:80'],
            'vat_no' => ['nullable', 'string', 'max:80'],
            'pan_no' => ['nullable', 'string', 'max:80'],
            'store_website' => ['nullable', 'string', 'max:190'],
            'show_signature' => ['nullable', 'boolean'],
            'signature' => ['nullable', 'string', 'max:500'],
            'bank_details' => ['nullable', 'string', 'max:2000'],
            'country' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:120'],
            'postcode' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:1000'],
            'store_logo' => ['nullable', 'string', 'max:500'],
            'timezone' => ['required', 'string', 'max:80'],
            'date_format' => ['required', 'in:dd-mm-yyyy,dd/mm/yyyy,mm-dd-yyyy,yyyy-mm-dd'],
            'time_format' => ['required', 'in:12,24'],
            'currency' => ['required', 'string', 'size:3'],
            'currency_placement' => ['required', 'in:Left,Right'],
            'decimals' => ['required', 'integer', 'min:0', 'max:4'],
            'qty_decimals' => ['required', 'integer', 'min:0', 'max:4'],
            'language_id' => ['required', 'in:fr,ar,en'],
            'round_off' => ['nullable', 'boolean'],
            'default_account_id' => ['nullable', 'string', 'max:120'],
            'sales_discount' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'sales_invoice_format_id' => ['nullable', 'string', 'max:40'],
            'pos_invoice_format_id' => ['nullable', 'string', 'max:40'],
            'mrp_column' => ['nullable', 'boolean'],
            'change_return' => ['nullable', 'boolean'],
            'previous_balance_bit' => ['nullable', 'boolean'],
            'number_to_words' => ['required', 'in:Default,Indian,Western,Off'],
            'sales_invoice_footer_text' => ['nullable', 'string', 'max:2000'],
            't_and_c_status' => ['nullable', 'boolean'],
            't_and_c_status_pos' => ['nullable', 'boolean'],
            'invoice_terms' => ['nullable', 'string', 'max:4000'],
            'toggle_header_footer' => ['nullable', 'boolean'],
            'category_init' => ['nullable', 'string', 'max:20'],
            'item_init' => ['nullable', 'string', 'max:20'],
            'supplier_init' => ['nullable', 'string', 'max:20'],
            'purchase_init' => ['nullable', 'string', 'max:20'],
            'purchase_return_init' => ['nullable', 'string', 'max:20'],
            'customer_init' => ['nullable', 'string', 'max:20'],
            'sales_init' => ['nullable', 'string', 'max:20'],
            'sales_return_init' => ['nullable', 'string', 'max:20'],
            'expense_init' => ['nullable', 'string', 'max:20'],
            'accounts_init' => ['nullable', 'string', 'max:20'],
            'quotation_init' => ['nullable', 'string', 'max:20'],
            'money_transfer_init' => ['nullable', 'string', 'max:20'],
            'sales_payment_init' => ['nullable', 'string', 'max:20'],
            'sales_return_payment_init' => ['nullable', 'string', 'max:20'],
            'purchase_payment_init' => ['nullable', 'string', 'max:20'],
            'purchase_return_payment_init' => ['nullable', 'string', 'max:20'],
            'expense_payment_init' => ['nullable', 'string', 'max:20'],
            'cust_advance_init' => ['nullable', 'string', 'max:20'],
        ]);

        foreach (['show_signature', 'round_off', 'mrp_column', 'change_return', 'previous_balance_bit', 't_and_c_status', 't_and_c_status_pos', 'toggle_header_footer'] as $boolean) {
            $data[$boolean] = $request->boolean($boolean);
        }

        $settings = $tenant->settings ?? [];
        $settings['company_profile'] = $data;
        $settings['receipt_header'] = $data['store_name'];
        $settings['locale'] = $data['language_id'] === 'ar' ? 'ar_MA' : ($data['language_id'] === 'en' ? 'en_US' : 'fr_MA');
        $settings['number_format'] = [
            'currency_placement' => $data['currency_placement'],
            'decimals' => (int) $data['decimals'],
            'qty_decimals' => (int) $data['qty_decimals'],
            'round_off' => $data['round_off'],
        ];

        $tenant->update([
            'name' => $data['store_name'],
            'currency' => strtoupper($data['currency']),
            'locale' => $settings['locale'],
            'timezone' => $data['timezone'],
            'phone' => $data['phone'] ?: $data['mobile'],
            'email' => $data['email'],
            'ice' => $data['gst_no'] ?: $tenant->ice,
            'address' => $data['address'],
            'settings' => $settings,
        ]);

        return redirect()
            ->route('module', ['module' => 'settings', 'section' => 'company'])
            ->with('status', 'Profil société mis à jour.');
    }

    public function updatePosSettings(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $settings = $tenant->settings ?? [];
        $settings['pos'] = array_merge($settings['pos'] ?? [], [
            'editable_price' => $request->boolean('editable_price'),
            'allow_oversell' => $request->boolean('allow_oversell'),
        ]);
        $tenant->update(['settings' => $settings]);

        return back()->with('status', 'Paramètres caisse mis à jour.');
    }

    public function updateCurrentStore(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $keys = collect($this->storeCatalog($tenant))->pluck('key')->all();
        $data = $request->validate([
            'current_store' => ['required', Rule::in($keys)],
        ]);

        $settings = $tenant->settings ?? [];
        $settings['current_store'] = $data['current_store'];
        $tenant->update(['settings' => $settings]);

        return back()->with('status', 'Magasin courant mis à jour.');
    }

    public function storePaymentType(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $data = $this->validateSettingsReference($request, 'payment_types');
        $this->upsertSettingsRecord($tenant, 'payment_types', $data);

        return redirect()->route('module', ['module' => 'settings', 'section' => 'payment-types'])->with('status', 'Type de paiement ajouté.');
    }

    public function updatePaymentType(Request $request, string $key): RedirectResponse
    {
        $tenant = $this->tenant();
        $data = $this->validateSettingsReference($request, 'payment_types');
        $this->upsertSettingsRecord($tenant, 'payment_types', $data, $key);

        return redirect()->route('module', ['module' => 'settings', 'section' => 'payment-types'])->with('status', 'Type de paiement mis à jour.');
    }

    public function destroyPaymentType(string $key): RedirectResponse
    {
        $this->deleteSettingsRecord($this->tenant(), 'payment_types', $key);

        return back()->with('status', 'Type de paiement supprimé.');
    }

    public function storeCountry(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $data = $this->validateSettingsReference($request, 'countries');
        $this->upsertSettingsRecord($tenant, 'countries', $data);

        return redirect()->route('module', ['module' => 'settings', 'section' => 'countries'])->with('status', 'Pays ajouté.');
    }

    public function updateCountry(Request $request, string $key): RedirectResponse
    {
        $tenant = $this->tenant();
        $data = $this->validateSettingsReference($request, 'countries');
        $this->upsertSettingsRecord($tenant, 'countries', $data, $key);

        return redirect()->route('module', ['module' => 'settings', 'section' => 'countries'])->with('status', 'Pays mis à jour.');
    }

    public function destroyCountry(string $key): RedirectResponse
    {
        $this->deleteSettingsRecord($this->tenant(), 'countries', $key);

        return back()->with('status', 'Pays supprimé.');
    }

    public function storeState(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $data = $this->validateSettingsReference($request, 'states') + [
            'country' => $request->string('country')->trim()->toString(),
        ];
        $this->upsertSettingsRecord($tenant, 'states', $data);

        return redirect()->route('module', ['module' => 'settings', 'section' => 'states'])->with('status', 'État / région ajouté.');
    }

    public function updateState(Request $request, string $key): RedirectResponse
    {
        $tenant = $this->tenant();
        $data = $this->validateSettingsReference($request, 'states') + [
            'country' => $request->string('country')->trim()->toString(),
        ];
        $this->upsertSettingsRecord($tenant, 'states', $data, $key);

        return redirect()->route('module', ['module' => 'settings', 'section' => 'states'])->with('status', 'État / région mis à jour.');
    }

    public function destroyState(string $key): RedirectResponse
    {
        $this->deleteSettingsRecord($this->tenant(), 'states', $key);

        return back()->with('status', 'État / région supprimé.');
    }

    public function storeTaxGroup(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'secondary_taxes' => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $data['is_active'] = $request->boolean('is_active', true);
        $this->upsertSettingsRecord($tenant, 'tax_groups', $data);

        return redirect()->route('module', ['module' => 'settings', 'section' => 'taxes'])->with('status', 'Groupe fiscal ajouté.');
    }

    public function updateTaxGroup(Request $request, string $key): RedirectResponse
    {
        $tenant = $this->tenant();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'secondary_taxes' => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $data['is_active'] = $request->boolean('is_active');
        $this->upsertSettingsRecord($tenant, 'tax_groups', $data, $key);

        return redirect()->route('module', ['module' => 'settings', 'section' => 'taxes'])->with('status', 'Groupe fiscal mis à jour.');
    }

    public function destroyTaxGroup(string $key): RedirectResponse
    {
        $this->deleteSettingsRecord($this->tenant(), 'tax_groups', $key);

        return back()->with('status', 'Groupe fiscal supprimé.');
    }

    public function updateAccountPassword(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', 'min:8', 'max:120'],
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            return back()->withErrors(['current_password' => 'Le mot de passe actuel est incorrect.']);
        }

        $user->update(['password' => $data['password']]);

        return redirect()->route('module', ['module' => 'settings', 'section' => 'password'])->with('status', 'Mot de passe mis à jour.');
    }

    public function storeStore(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $data = $this->validateStore($request);
        $settings = $tenant->settings ?? [];
        $stores = $this->storeCatalog($tenant);
        $key = $this->uniqueStoreKey($stores, $data['name']);
        $stores[] = $this->storePayload($data, $key);
        $settings['stores'] = $stores;
        $settings['current_store'] ??= $key;
        $tenant->update(['settings' => $settings]);

        return redirect()
            ->route('module', ['module' => 'settings', 'section' => 'warehouses'])
            ->with('status', 'Magasin '.$data['name'].' ajouté.');
    }

    public function updateStore(Request $request, string $storeKey): RedirectResponse
    {
        $tenant = $this->tenant();
        $data = $this->validateStore($request);
        $settings = $tenant->settings ?? [];
        $stores = collect($this->storeCatalog($tenant))->map(function (array $store) use ($storeKey, $data): array {
            return $store['key'] === $storeKey ? $this->storePayload($data, $storeKey) : $store;
        })->values()->all();

        abort_unless(collect($stores)->contains(fn (array $store) => $store['key'] === $storeKey), 404);

        $settings['stores'] = $stores;
        $tenant->update(['settings' => $settings]);

        return redirect()
            ->route('module', ['module' => 'settings', 'section' => 'warehouses'])
            ->with('status', 'Magasin mis à jour.');
    }

    public function destroyStore(string $storeKey): RedirectResponse
    {
        $tenant = $this->tenant();
        $settings = $tenant->settings ?? [];
        $stores = collect($this->storeCatalog($tenant))->reject(fn (array $store) => $store['key'] === $storeKey)->values()->all();
        abort_if(count($stores) === 0, 422, 'Gardez au moins un magasin actif.');
        $settings['stores'] = $stores;

        if (($settings['current_store'] ?? null) === $storeKey) {
            $settings['current_store'] = $stores[0]['key'];
        }

        $tenant->update(['settings' => $settings]);

        return back()->with('status', 'Magasin supprimé.');
    }

    public function storeUser(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $data = $this->validateUserAccess($request, $tenant);

        $user = \App\Models\User::create([
            'current_tenant_id' => $tenant->id,
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => Hash::make($data['password']),
            'avatar_color' => $data['avatar_color'] ?? '#4F46E5',
            'is_active' => $request->boolean('is_active', true),
        ]);

        $tenant->users()->syncWithoutDetaching([
            $user->id => $this->tenantUserPayload($data),
        ]);

        return redirect()
            ->route('module', ['module' => 'settings', 'section' => 'users'])
            ->with('status', 'Utilisateur '.$user->name.' ajouté.');
    }

    public function updateUser(Request $request, \App\Models\User $user): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless($tenant->users()->whereKey($user->id)->exists(), 403);

        $data = $this->validateUserAccess($request, $tenant, $user);
        $payload = [
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'avatar_color' => $data['avatar_color'] ?? $user->avatar_color,
            'is_active' => $request->boolean('is_active'),
        ];

        if (! empty($data['password'])) {
            $payload['password'] = Hash::make($data['password']);
        }

        $user->update($payload);
        $tenant->users()->updateExistingPivot($user->id, $this->tenantUserPayload($data));

        return redirect()
            ->route('module', ['module' => 'settings', 'section' => 'users'])
            ->with('status', 'Utilisateur '.$user->name.' mis à jour.');
    }

    public function destroyUser(\App\Models\User $user): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless($tenant->users()->whereKey($user->id)->exists(), 403);

        $tenant->users()->detach($user->id);

        return back()->with('status', 'Accès utilisateur retiré.');
    }

    public function storeRole(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $data = $this->validateRole($request, $tenant);

        Role::create($data + ['tenant_id' => $tenant->id]);

        return redirect()
            ->route('module', ['module' => 'settings', 'section' => 'roles'])
            ->with('status', 'Rôle créé.');
    }

    public function updateRole(Request $request, Role $role): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless($role->tenant_id === $tenant->id, 403);

        $role->update($this->validateRole($request, $tenant, $role));

        return redirect()
            ->route('module', ['module' => 'settings', 'section' => 'roles'])
            ->with('status', 'Rôle mis à jour.');
    }

    public function destroyRole(Role $role): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless($role->tenant_id === $tenant->id, 403);
        abort_if($tenant->users()->wherePivot('role', $role->key)->exists(), 422, 'Ce rôle est encore utilisé.');

        $role->delete();

        return back()->with('status', 'Rôle supprimé.');
    }

    public function pos(Request $request): View
    {
        $tenant = $this->tenant();
        $query = trim((string) $request->query('q'));
        $type = $request->query('type', 'all');
        $stock = $request->query('stock', 'available');
        $allowOversell = (bool) data_get($tenant->settings, 'pos.allow_oversell', false);
        $lastSaleId = $request->query('sale', session('last_pos_sale_id'));
        $resumeTicket = null;
        if ($request->filled('ticket')) {
            $resumeTicket = PosTicket::where('tenant_id', $tenant->id)
                ->where('status', 'held')
                ->whereKey((int) $request->query('ticket'))
                ->first();
        }

        $topSold = DB::table('sale_items')
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->where('sales.tenant_id', $tenant->id)
            ->whereNotNull('sale_items.item_id')
            ->selectRaw('sale_items.item_id, sum(sale_items.quantity) as sold_quantity')
            ->groupBy('sale_items.item_id')
            ->pluck('sold_quantity', 'item_id');

        $items = $tenant->items()
            ->with(['category', 'brand', 'unit', 'tax'])
            ->where('status', 'active')
            ->when($query, fn (Builder $builder) => $builder->where(function (Builder $builder) use ($query): void {
                $builder->where('title', 'like', "%{$query}%")
                    ->orWhere('item_code', 'like', "%{$query}%")
                    ->orWhere('sku', 'like', "%{$query}%")
                    ->orWhere('isbn', 'like', "%{$query}%")
                    ->orWhere('barcode', 'like', "%{$query}%")
                    ->orWhere('custom_barcode1', 'like', "%{$query}%");
            }))
            ->when($type !== 'all', fn (Builder $builder) => $builder->where('type', $type))
            ->when($stock === 'available' && ! $allowOversell, fn (Builder $builder) => $builder->where(function (Builder $builder): void {
                $builder->where('type', 'service')->orWhere('stock_quantity', '>', 0);
            }))
            ->when($stock === 'low', fn (Builder $builder) => $builder->where('type', '!=', 'service')->whereColumn('stock_quantity', '<=', 'min_stock_threshold'))
            ->orderByRaw("case when type != 'service' and stock_quantity <= 0 then 2 when type != 'service' and stock_quantity <= min_stock_threshold then 0 else 1 end")
            ->orderBy('title')
            ->take(120)
            ->get()
            ->sortByDesc(fn (Item $item) => (int) ($topSold[$item->id] ?? 0))
            ->values();

        $heldTickets = PosTicket::where('tenant_id', $tenant->id)->with('contact')->where('status', 'held')->latest('held_at')->take(8)->get();
        $heldTicketItems = Item::where('tenant_id', $tenant->id)
            ->whereIn('id', $heldTickets->flatMap(fn (PosTicket $ticket) => collect($ticket->cart)->pluck('item_id'))->unique()->values())
            ->get()
            ->keyBy('id');

        return view('librairepro.pos', [
            'tenant' => $tenant,
            'active' => 'sales',
            'items' => $items,
            'clients' => Contact::where('tenant_id', $tenant->id)->where('kind', 'client')->orderBy('name')->take(80)->get(),
            'recentSales' => $tenant->sales()->with('contact')->latest('sold_at')->take(5)->get(),
            'lastSale' => $lastSaleId ? $tenant->sales()->with(['contact', 'items'])->whereKey($lastSaleId)->first() : null,
            'heldTickets' => $heldTickets,
            'heldTicketItems' => $heldTicketItems,
            'resumeTicket' => $resumeTicket,
            'nextSaleNumber' => $this->nextSaleNumber($tenant),
            'nextTicketNumber' => $this->nextTicketNumber($tenant),
            'categories' => Category::where('tenant_id', $tenant->id)->orderBy('name')->get(),
            'brands' => Brand::where('tenant_id', $tenant->id)->orderBy('name')->get(),
            'units' => Unit::where('tenant_id', $tenant->id)->orderBy('name')->get(),
            'topSold' => $topSold,
            'query' => $query,
            'type' => $type,
            'stock' => $stock,
            'priceEditable' => (bool) data_get($tenant->settings, 'pos.editable_price', true),
            'allowOversell' => $allowOversell,
        ]);
    }

    public function storePosSale(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $data = $request->validate([
            'contact_id' => ['nullable', 'integer', Rule::exists('contacts', 'id')->where('tenant_id', $tenant->id)],
            'client_name' => ['nullable', 'string', 'max:160'],
            'client_phone' => ['nullable', 'string', 'max:60'],
            'cart' => ['required', 'json'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'cash_amount' => ['nullable', 'numeric', 'min:0'],
            'card_amount' => ['nullable', 'numeric', 'min:0'],
            'transfer_amount' => ['nullable', 'numeric', 'min:0'],
            'advance_amount' => ['nullable', 'numeric', 'min:0'],
            'receipt_channel' => ['nullable', 'in:print,email,whatsapp,none'],
            'note' => ['nullable', 'string', 'max:500'],
            'ticket_id' => ['nullable', 'integer', Rule::exists('pos_tickets', 'id')->where('tenant_id', $tenant->id)->where('status', 'held')],
        ]);

        $priceEditable = (bool) data_get($tenant->settings, 'pos.editable_price', true);
        $allowOversell = (bool) data_get($tenant->settings, 'pos.allow_oversell', false);
        $cart = collect(json_decode($data['cart'], true));
        if ($cart->isEmpty()) {
            return back()->withErrors(['cart' => 'Ajoutez au moins un article au panier.'])->withInput();
        }

        $lineItems = $cart->map(function ($line) use ($priceEditable) {
            $price = $priceEditable && array_key_exists('price', $line)
                ? round(max(0, (float) $line['price']), 2)
                : null;

            return [
                'item_id' => (int) ($line['id'] ?? 0),
                'quantity' => max(1, (int) ($line['quantity'] ?? 1)),
                'unit_price' => $price,
                'note' => mb_substr(trim((string) ($line['note'] ?? '')), 0, 160),
            ];
        })->filter(fn ($line) => $line['item_id'] > 0)
            ->values();

        if ($lineItems->isEmpty()) {
            return back()->withErrors(['cart' => 'Le panier est invalide. Rechargez la caisse et réessayez.'])->withInput();
        }

        $discount = round((float) ($data['discount_amount'] ?? 0), 2);
        $payments = [
            'cash' => round((float) ($data['cash_amount'] ?? 0), 2),
            'card' => round((float) ($data['card_amount'] ?? 0), 2),
            'transfer' => round((float) ($data['transfer_amount'] ?? 0), 2),
            'advance' => round((float) ($data['advance_amount'] ?? 0), 2),
        ];

        try {
            $sale = DB::transaction(function () use ($tenant, $data, $lineItems, $discount, $payments, $priceEditable, $allowOversell) {
                $contact = null;
                if (! empty($data['contact_id'])) {
                    $contact = Contact::where('tenant_id', $tenant->id)->whereKey($data['contact_id'])->lockForUpdate()->firstOrFail();
                } elseif (! empty($data['client_name'])) {
                    $contact = Contact::create([
                        'tenant_id' => $tenant->id,
                        'kind' => 'client',
                        'name' => $data['client_name'],
                        'phone' => $data['client_phone'] ?? null,
                    ]);
                }

                $items = Item::query()
                    ->where('tenant_id', $tenant->id)
                    ->whereIn('id', $lineItems->pluck('item_id'))
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                $subtotal = 0.0;
                $saleLines = [];

                foreach ($lineItems as $line) {
                    $item = $items->get($line['item_id']);
                    if (! $item || $item->status !== 'active') {
                        throw new \RuntimeException('Un article du panier est indisponible.');
                    }

                    if (! $allowOversell && $item->type !== 'service' && $item->stock_quantity < $line['quantity']) {
                        throw new \RuntimeException("Stock insuffisant pour {$item->title}.");
                    }

                    $catalogPrice = (float) $item->sale_price;
                    $unitPrice = $priceEditable && $line['unit_price'] !== null ? (float) $line['unit_price'] : $catalogPrice;
                    $lineTotal = round($unitPrice * $line['quantity'], 2);
                    $subtotal += $lineTotal;
                    $saleLines[] = [
                        'item' => $item,
                        'quantity' => $line['quantity'],
                        'unit_price' => $unitPrice,
                        'catalog_price' => $catalogPrice,
                        'total_price' => $lineTotal,
                        'note' => $line['note'],
                        'price_overridden' => abs($unitPrice - $catalogPrice) > 0.001,
                    ];
                }

                $total = max(0, round($subtotal - $discount, 2));
                $paid = round(array_sum($payments), 2);
                if ($paid + 0.001 < $total) {
                    throw new \RuntimeException('Le montant payé est inférieur au total.');
                }

                if (($payments['advance'] ?? 0) > 0 && ! $contact) {
                    throw new \RuntimeException('Sélectionnez un client pour utiliser une avance.');
                }

                if ($contact && ($payments['advance'] ?? 0) > 0) {
                    if ((float) $contact->advance_balance < $payments['advance']) {
                        throw new \RuntimeException('Avance client insuffisante.');
                    }
                    $contact->decrement('advance_balance', $payments['advance']);
                }

                $paymentMethod = collect($payments)->filter(fn ($amount) => $amount > 0.001)->keys()->join('+') ?: 'cash';
                $saleNumber = $this->nextSaleNumber($tenant);
                $changeAmount = max(0, round($paid - $total, 2));
                $cashChange = min($payments['cash'], $changeAmount);
                $cashDrawerIn = max(0, round($payments['cash'] - $cashChange, 2));
                $sale = Sale::create([
                    'tenant_id' => $tenant->id,
                    'contact_id' => $contact?->id,
                    'user_id' => auth()->id(),
                    'number' => $saleNumber,
                    'status' => 'paid',
                    'payment_method' => $paymentMethod,
                    'subtotal_amount' => $subtotal,
                    'discount_amount' => $discount,
                    'tax_amount' => round($total * 0.2 / 1.2, 2),
                    'total_amount' => $total,
                    'sold_at' => now(),
                    'metadata' => [
                        'invoice_number' => $this->invoiceNumber($saleNumber),
                        'reference_number' => ! empty($data['ticket_id']) ? PosTicket::whereKey($data['ticket_id'])->value('number') : null,
                        'payments' => $payments,
                        'paid_amount' => $paid,
                        'change_amount' => $changeAmount,
                        'cash_register' => [
                            'cash_received' => $payments['cash'],
                            'cash_change' => $cashChange,
                            'cash_drawer_in' => $cashDrawerIn,
                            'paid_amount' => $paid,
                            'expected_total' => $total,
                        ],
                        'line_adjustments' => collect($saleLines)->map(fn (array $line) => [
                            'item_id' => $line['item']->id,
                            'name' => $line['item']->title,
                            'quantity' => $line['quantity'],
                            'catalog_price' => $line['catalog_price'],
                            'unit_price' => $line['unit_price'],
                            'price_overridden' => $line['price_overridden'],
                            'note' => $line['note'],
                        ])->values()->all(),
                        'receipt_channel' => $data['receipt_channel'] ?? 'print',
                        'note' => $data['note'] ?? null,
                    ],
                ]);

                foreach ($saleLines as $line) {
                    $sale->items()->create([
                        'item_id' => $line['item']->id,
                        'name' => $line['item']->title,
                        'quantity' => $line['quantity'],
                        'unit_price' => $line['unit_price'],
                        'total_price' => $line['total_price'],
                    ]);

                    if ($line['item']->type !== 'service') {
                        $line['item']->decrement('stock_quantity', $line['quantity']);
                        if (! $allowOversell && $line['item']->fresh()->stock_quantity <= 0) {
                            $line['item']->update(['status' => 'out_of_stock']);
                        }
                    }
                }

                $remainingPaymentToAllocate = $total;
                foreach ($payments as $method => $amount) {
                    $allocatedAmount = min($amount, $remainingPaymentToAllocate);
                    if ($allocatedAmount <= 0.001) {
                        continue;
                    }

                    SalePayment::create([
                        'tenant_id' => $tenant->id,
                        'sale_id' => $sale->id,
                        'contact_id' => $contact?->id,
                        'user_id' => auth()->id(),
                        'number' => $this->nextPaymentNumber($tenant),
                        'method' => $method,
                        'amount' => $allocatedAmount,
                        'paid_at' => now(),
                        'reference' => $sale->number,
                        'note' => $method === 'cash' && $amount > $allocatedAmount ? 'Paiement POS · reçu '.number_format($amount, 2, ',', ' ').' DH' : 'Paiement POS',
                    ]);
                    $remainingPaymentToAllocate = max(0, round($remainingPaymentToAllocate - $allocatedAmount, 2));
                }

                if (! empty($data['ticket_id'])) {
                    PosTicket::where('tenant_id', $tenant->id)
                        ->where('status', 'held')
                        ->whereKey($data['ticket_id'])
                        ->update([
                            'status' => 'converted',
                            'converted_sale_id' => $sale->id,
                        ]);
                }

                return $sale;
            });
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['cart' => $exception->getMessage()])->withInput();
        }

        return redirect()
            ->route('pos', ['sale' => $sale->id])
            ->with('status', 'Vente '.$sale->number.' encaissée.')
            ->with('last_pos_sale_id', $sale->id);
    }

    public function holdPosTicket(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $data = $request->validate([
            'contact_id' => ['nullable', 'integer', Rule::exists('contacts', 'id')->where('tenant_id', $tenant->id)],
            'client_name' => ['nullable', 'string', 'max:160'],
            'client_phone' => ['nullable', 'string', 'max:60'],
            'cart' => ['required', 'json'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],
            'ticket_id' => ['nullable', 'integer', Rule::exists('pos_tickets', 'id')->where('tenant_id', $tenant->id)->where('status', 'held')],
        ]);

        $cart = collect(json_decode($data['cart'], true));
        if ($cart->isEmpty()) {
            return back()->withErrors(['cart' => 'Ajoutez au moins un article avant de mettre le ticket en attente.'])->withInput();
        }

        $lineItems = $this->normalizedCartLines($cart, (bool) data_get($tenant->settings, 'pos.editable_price', true));
        if ($lineItems->isEmpty()) {
            return back()->withErrors(['cart' => 'Le panier est invalide. Rechargez la caisse et réessayez.'])->withInput();
        }

        try {
            $ticket = DB::transaction(function () use ($tenant, $data, $lineItems) {
                $contactId = $data['contact_id'] ?? null;
                if (! $contactId && ! empty($data['client_name'])) {
                    $contact = Contact::create([
                        'tenant_id' => $tenant->id,
                        'kind' => 'client',
                        'name' => $data['client_name'],
                        'phone' => $data['client_phone'] ?? null,
                    ]);
                    $contactId = $contact->id;
                }

                $totals = $this->cartTotals($tenant, $lineItems, (float) ($data['discount_amount'] ?? 0));
                $payload = [
                    'tenant_id' => $tenant->id,
                    'contact_id' => $contactId,
                    'user_id' => auth()->id(),
                    'cart' => $lineItems->values()->all(),
                    'subtotal_amount' => $totals['subtotal'],
                    'discount_amount' => $totals['discount'],
                    'tax_amount' => $totals['tax'],
                    'total_amount' => $totals['total'],
                    'note' => $data['note'] ?? null,
                    'held_at' => now(),
                ];

                if (! empty($data['ticket_id'])) {
                    $ticket = PosTicket::where('tenant_id', $tenant->id)->where('status', 'held')->whereKey($data['ticket_id'])->firstOrFail();
                    $ticket->update($payload);

                    return $ticket;
                }

                return PosTicket::create($payload + [
                    'number' => $this->nextTicketNumber($tenant),
                    'status' => 'held',
                ]);
            });
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['cart' => $exception->getMessage()])->withInput();
        }

        return redirect()
            ->route('pos')
            ->with('status', 'Ticket '.$ticket->number.' mis en attente.');
    }

    public function destroyPosTicket(PosTicket $ticket): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless($ticket->tenant_id === $tenant->id && $ticket->status === 'held', 404);
        $number = $ticket->number;
        $ticket->update(['status' => 'void']);

        return redirect()->route('pos')->with('status', 'Ticket '.$number.' annulé.');
    }

    public function refundSale(Request $request, Sale $sale): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless($sale->tenant_id === $tenant->id, 404);
        if ($sale->status === 'refunded') {
            return back()->withErrors(['sale' => 'Cette vente est déjà remboursée.']);
        }

        $data = $request->validate([
            'refund_method' => ['required', 'in:cash,card,transfer,credit'],
            'refund_reason' => ['nullable', 'string', 'max:500'],
            'restock' => ['nullable', 'boolean'],
        ]);

        DB::transaction(function () use ($tenant, $sale, $data): void {
            $sale->load('items');
            $lines = [];

            if ($data['restock'] ?? true) {
                foreach ($sale->items as $line) {
                    $lines[] = [
                        'sale_item_id' => $line->id,
                        'item_id' => $line->item_id,
                        'name' => $line->name,
                        'quantity' => $line->quantity,
                        'unit_price' => (float) $line->unit_price,
                        'total_price' => (float) $line->total_price,
                    ];

                    if (! $line->item_id) {
                        continue;
                    }

                    $item = Item::whereKey($line->item_id)->lockForUpdate()->first();
                    if ($item && $item->type !== 'service') {
                        $item->increment('stock_quantity', $line->quantity);
                        if ($item->status === 'out_of_stock' && $item->fresh()->stock_quantity > 0) {
                            $item->update(['status' => 'active']);
                        }
                    }
                }
            } else {
                $lines = $sale->items->map(fn ($line) => [
                    'sale_item_id' => $line->id,
                    'item_id' => $line->item_id,
                    'name' => $line->name,
                    'quantity' => $line->quantity,
                    'unit_price' => (float) $line->unit_price,
                    'total_price' => (float) $line->total_price,
                ])->values()->all();
            }

            SaleReturn::create([
                'tenant_id' => $tenant->id,
                'sale_id' => $sale->id,
                'contact_id' => $sale->contact_id,
                'user_id' => auth()->id(),
                'number' => $this->nextReturnNumber($tenant),
                'status' => 'approved',
                'refund_method' => $data['refund_method'],
                'total_amount' => $sale->total_amount,
                'lines' => $lines,
                'reason' => $data['refund_reason'] ?? null,
                'restock' => (bool) ($data['restock'] ?? true),
                'returned_at' => now(),
            ]);

            $metadata = $sale->metadata ?? [];
            $metadata['refund'] = [
                'method' => $data['refund_method'],
                'reason' => $data['refund_reason'] ?? null,
                'restock' => (bool) ($data['restock'] ?? true),
                'amount' => (float) $sale->total_amount,
                'refunded_at' => now()->toIso8601String(),
            ];

            $sale->update([
                'status' => 'refunded',
                'metadata' => $metadata,
            ]);
        });

        return back()->with('status', 'Vente '.$sale->number.' remboursée.');
    }

    public function storeSalePayment(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $data = $request->validate([
            'sale_id' => ['required', 'integer', Rule::exists('sales', 'id')->where('tenant_id', $tenant->id)],
            'method' => ['required', 'in:cash,card,transfer,advance'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'paid_at' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:160'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($tenant, $data): void {
            $sale = Sale::where('tenant_id', $tenant->id)->whereKey($data['sale_id'])->lockForUpdate()->firstOrFail();
            $paidBefore = $this->salePaidAmount($sale);
            $remaining = (float) $sale->total_amount - $paidBefore;
            if ($remaining <= 0.001) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'sale_id' => 'Cette vente est déjà entièrement payée.',
                ]);
            }
            $amount = min((float) $data['amount'], $remaining);

            SalePayment::create([
                'tenant_id' => $tenant->id,
                'sale_id' => $sale->id,
                'contact_id' => $sale->contact_id,
                'user_id' => auth()->id(),
                'number' => $this->nextPaymentNumber($tenant),
                'method' => $data['method'],
                'amount' => $amount,
                'paid_at' => ! empty($data['paid_at']) ? Carbon::parse($data['paid_at']) : now(),
                'reference' => $data['reference'] ?? null,
                'note' => $data['note'] ?? null,
            ]);

            $metadata = $sale->metadata ?? [];
            $metadata['paid_amount'] = min((float) $sale->total_amount, $paidBefore + $amount);
            $sale->update([
                'status' => $metadata['paid_amount'] + 0.001 >= (float) $sale->total_amount ? 'paid' : 'partial',
                'metadata' => $metadata,
            ]);
        });

        return back()->with('status', 'Paiement ajouté.');
    }

    public function storeDeliveryOrder(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $data = $request->validate([
            'sale_id' => ['required', 'integer', Rule::exists('sales', 'id')->where('tenant_id', $tenant->id)],
            'delivery_address' => ['nullable', 'string', 'max:1000'],
            'assigned_to' => ['nullable', 'string', 'max:160'],
            'scheduled_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $sale = Sale::where('tenant_id', $tenant->id)->with('contact')->whereKey($data['sale_id'])->firstOrFail();
        DeliveryOrder::create([
            'tenant_id' => $tenant->id,
            'sale_id' => $sale->id,
            'contact_id' => $sale->contact_id,
            'user_id' => auth()->id(),
            'number' => $this->nextDeliveryNumber($tenant),
            'status' => 'pending',
            'assigned_to' => $data['assigned_to'] ?? null,
            'delivery_address' => $data['delivery_address'] ?? $sale->contact?->address,
            'note' => $data['note'] ?? null,
            'scheduled_at' => ! empty($data['scheduled_at']) ? Carbon::parse($data['scheduled_at']) : null,
        ]);

        return back()->with('status', 'Livraison créée.');
    }

    public function updateDeliveryOrder(Request $request, DeliveryOrder $delivery): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless($delivery->tenant_id === $tenant->id, 404);
        $data = $request->validate([
            'status' => ['required', 'in:pending,preparing,dispatched,delivered,failed'],
            'assigned_to' => ['nullable', 'string', 'max:160'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $timestamps = [];
        if ($data['status'] === 'dispatched' && ! $delivery->dispatched_at) {
            $timestamps['dispatched_at'] = now();
        }
        if ($data['status'] === 'delivered' && ! $delivery->delivered_at) {
            $timestamps['delivered_at'] = now();
        }

        $delivery->update($data + $timestamps);

        return back()->with('status', 'Livraison '.$delivery->number.' mise à jour.');
    }

    public function storeQuotation(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $data = $request->validate([
            'contact_id' => ['nullable', 'integer', Rule::exists('contacts', 'id')->where('tenant_id', $tenant->id)->where('kind', 'client')],
            'client_name' => ['nullable', 'string', 'max:160'],
            'client_phone' => ['nullable', 'string', 'max:60'],
            'quoted_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
            'status' => ['required', 'in:draft,sent,accepted'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'reference' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:700'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['nullable', 'integer', Rule::exists('items', 'id')->where('tenant_id', $tenant->id)],
            'items.*.quantity' => ['nullable', 'integer', 'min:1'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $lines = collect($data['items'])
            ->map(fn (array $line) => [
                'item_id' => (int) ($line['item_id'] ?? 0),
                'quantity' => max(0, (int) ($line['quantity'] ?? 0)),
                'unit_price' => isset($line['unit_price']) && $line['unit_price'] !== '' ? round(max(0, (float) $line['unit_price']), 2) : null,
            ])
            ->filter(fn (array $line) => $line['item_id'] > 0 && $line['quantity'] > 0)
            ->values();

        if ($lines->isEmpty()) {
            return back()->withErrors(['items' => 'Ajoutez au moins un article au devis.'])->withInput();
        }

        $quotation = DB::transaction(function () use ($tenant, $data, $lines): Quotation {
            $contactId = $data['contact_id'] ?? null;
            if (! $contactId && filled($data['client_name'] ?? null)) {
                $contactId = Contact::create([
                    'tenant_id' => $tenant->id,
                    'kind' => 'client',
                    'name' => $data['client_name'],
                    'phone' => $data['client_phone'] ?? null,
                    'client_type' => 'individual',
                ])->id;
            }

            $items = Item::where('tenant_id', $tenant->id)
                ->whereIn('id', $lines->pluck('item_id'))
                ->get()
                ->keyBy('id');

            $payloadLines = [];
            $subtotal = 0.0;
            foreach ($lines as $line) {
                $item = $items->get($line['item_id']);
                if (! $item || $item->status !== 'active') {
                    throw new \RuntimeException('Un article du devis est indisponible.');
                }

                $unitPrice = $line['unit_price'] ?? (float) $item->sale_price;
                $lineTotal = round($line['quantity'] * $unitPrice, 2);
                $subtotal += $lineTotal;
                $payloadLines[] = [
                    'item_id' => $item->id,
                    'name' => $item->title,
                    'barcode' => $item->barcode ?? $item->isbn ?? null,
                    'quantity' => $line['quantity'],
                    'unit_price' => $unitPrice,
                    'total_price' => $lineTotal,
                ];
            }

            $discount = min(round((float) ($data['discount_amount'] ?? 0), 2), $subtotal);
            $total = max(0, round($subtotal - $discount, 2));

            return Quotation::create([
                'tenant_id' => $tenant->id,
                'contact_id' => $contactId,
                'number' => $this->nextQuotationNumber($tenant),
                'status' => $data['status'],
                'subtotal_amount' => round($subtotal, 2),
                'discount_amount' => $discount,
                'tax_amount' => round($total * 0.2 / 1.2, 2),
                'total_amount' => $total,
                'quoted_at' => ! empty($data['quoted_at']) ? Carbon::parse($data['quoted_at']) : now(),
                'expires_at' => $data['expires_at'] ?? now()->addDays(15)->toDateString(),
                'lines' => $payloadLines,
                'metadata' => [
                    'reference' => $data['reference'] ?? null,
                    'note' => $data['note'] ?? null,
                    'client_name' => $data['client_name'] ?? null,
                    'client_phone' => $data['client_phone'] ?? null,
                ],
            ]);
        });

        return redirect()
            ->route('module', ['module' => 'sales', 'section' => 'quotes'])
            ->with('status', 'Devis '.$quotation->number.' enregistré.');
    }

    public function updateQuotationStatus(Request $request, Quotation $quotation): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless($quotation->tenant_id === $tenant->id, 404);

        $data = $request->validate([
            'status' => ['required', 'in:draft,sent,accepted,rejected,expired'],
        ]);

        $quotation->update($data);

        return back()->with('status', 'Devis '.$quotation->number.' mis à jour.');
    }

    public function convertQuotationToSale(Quotation $quotation): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless($quotation->tenant_id === $tenant->id, 404);

        if ($quotation->converted_sale_id) {
            return redirect()
                ->route('module', ['module' => 'sales', 'section' => 'list'])
                ->with('status', 'Ce devis est déjà converti.');
        }

        try {
            $sale = DB::transaction(function () use ($tenant, $quotation): Sale {
                $items = Item::where('tenant_id', $tenant->id)
                    ->whereIn('id', collect($quotation->lines)->pluck('item_id')->filter())
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                $saleNumber = $this->nextSaleNumber($tenant);
                $sale = Sale::create([
                    'tenant_id' => $tenant->id,
                    'contact_id' => $quotation->contact_id,
                    'user_id' => auth()->id(),
                    'number' => $saleNumber,
                    'status' => 'unpaid',
                    'payment_method' => 'devis',
                    'subtotal_amount' => $quotation->subtotal_amount,
                    'discount_amount' => $quotation->discount_amount,
                    'tax_amount' => $quotation->tax_amount,
                    'total_amount' => $quotation->total_amount,
                    'sold_at' => now(),
                    'metadata' => [
                        'invoice_number' => $this->invoiceNumber($saleNumber),
                        'reference_number' => $quotation->number,
                        'paid_amount' => 0,
                        'due_date' => $quotation->expires_at?->toDateString(),
                        'source' => 'quotation',
                    ],
                ]);

                foreach ($quotation->lines as $line) {
                    $item = $items->get((int) ($line['item_id'] ?? 0));
                    if ($item && $item->type !== 'service') {
                        if ($item->stock_quantity < (int) $line['quantity']) {
                            throw new \RuntimeException("Stock insuffisant pour {$item->title}.");
                        }
                        $item->decrement('stock_quantity', (int) $line['quantity']);
                    }

                    $sale->items()->create([
                        'item_id' => $line['item_id'] ?? null,
                        'name' => $line['name'],
                        'quantity' => (int) $line['quantity'],
                        'unit_price' => (float) $line['unit_price'],
                        'total_price' => (float) $line['total_price'],
                    ]);
                }

                $quotation->update([
                    'status' => 'accepted',
                    'converted_sale_id' => $sale->id,
                ]);

                return $sale;
            });
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['quotation' => $exception->getMessage()]);
        }

        return redirect()
            ->route('module', ['module' => 'sales', 'section' => 'list'])
            ->with('status', 'Devis '.$quotation->number.' converti en vente '.$sale->number.'.');
    }

    public function storeExpense(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $data = $request->validate([
            'label' => ['required', 'string', 'max:180'],
            'category' => ['required', 'string', 'max:120'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'spent_at' => ['nullable', 'date'],
            'payment_method' => ['required', 'in:cash,card,transfer,cheque,other'],
            'reference' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:700'],
        ]);

        ExpenseCategory::firstOrCreate(
            ['tenant_id' => $tenant->id, 'name' => $data['category']],
            ['color' => '#4F46E5']
        );

        $expense = Expense::create([
            'tenant_id' => $tenant->id,
            'number' => $this->nextExpenseNumber($tenant),
            'category' => $data['category'],
            'label' => $data['label'],
            'amount' => round((float) $data['amount'], 2),
            'payment_method' => $data['payment_method'],
            'reference' => $data['reference'] ?? null,
            'note' => $data['note'] ?? null,
            'spent_at' => $data['spent_at'] ?? now()->toDateString(),
        ]);

        return redirect()
            ->route('module', ['module' => 'finance', 'section' => 'expenses'])
            ->with('status', 'Dépense '.$expense->number.' enregistrée.');
    }

    public function storeFinancialAccount(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $storeKeys = collect($this->storeCatalog($tenant))->pluck('key')->all();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160', Rule::unique('financial_accounts')->where('tenant_id', $tenant->id)],
            'type' => ['required', Rule::in(['cash', 'bank', 'card', 'mobile', 'other'])],
            'store_key' => ['nullable', Rule::in($storeKeys)],
            'bank_name' => ['nullable', 'string', 'max:160'],
            'account_number' => ['nullable', 'string', 'max:160'],
            'holder_name' => ['nullable', 'string', 'max:160'],
            'opening_balance' => ['nullable', 'numeric', 'min:-99999999', 'max:99999999'],
            'description' => ['nullable', 'string', 'max:700'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $opening = round((float) ($data['opening_balance'] ?? 0), 2);
        $account = FinancialAccount::create([
            'tenant_id' => $tenant->id,
            'store_key' => $data['store_key'] ?? $this->currentStore($tenant)['key'],
            'name' => $data['name'],
            'type' => $data['type'],
            'bank_name' => $data['bank_name'] ?? null,
            'account_number' => $data['account_number'] ?? null,
            'holder_name' => $data['holder_name'] ?? null,
            'opening_balance' => $opening,
            'current_balance' => 0,
            'description' => $data['description'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ]);

        if (abs($opening) > 0.001) {
            $this->recordAccountTransaction($tenant, $account, 'opening', $opening >= 0 ? 'in' : 'out', abs($opening), [
                'note' => 'Solde initial',
                'payment_method' => 'opening',
            ]);
        }

        return redirect()->route('module', ['module' => 'finance', 'section' => 'accounts'])->with('status', 'Compte '.$account->name.' ajouté.');
    }

    public function updateFinancialAccount(Request $request, FinancialAccount $account): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless((int) $account->tenant_id === (int) $tenant->id, 403);
        $storeKeys = collect($this->storeCatalog($tenant))->pluck('key')->all();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160', Rule::unique('financial_accounts')->where('tenant_id', $tenant->id)->ignore($account->id)],
            'type' => ['required', Rule::in(['cash', 'bank', 'card', 'mobile', 'other'])],
            'store_key' => ['nullable', Rule::in($storeKeys)],
            'bank_name' => ['nullable', 'string', 'max:160'],
            'account_number' => ['nullable', 'string', 'max:160'],
            'holder_name' => ['nullable', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:700'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $account->update($data + ['is_active' => $request->boolean('is_active')]);

        return redirect()->route('module', ['module' => 'finance', 'section' => 'accounts'])->with('status', 'Compte mis à jour.');
    }

    public function destroyFinancialAccount(FinancialAccount $account): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless((int) $account->tenant_id === (int) $tenant->id, 403);

        if ($account->transactions()->exists()) {
            return back()->withErrors(['account' => 'Ce compte contient des transactions. Désactivez-le plutôt que de le supprimer.']);
        }

        $account->delete();

        return back()->with('status', 'Compte supprimé.');
    }

    public function storeMoneyDeposit(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $data = $request->validate([
            'financial_account_id' => ['required', 'integer', Rule::exists('financial_accounts', 'id')->where('tenant_id', $tenant->id)],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['required', 'in:cash,card,transfer,cheque,other'],
            'reference' => ['nullable', 'string', 'max:160'],
            'note' => ['nullable', 'string', 'max:700'],
            'transacted_at' => ['nullable', 'date'],
        ]);

        $transaction = DB::transaction(function () use ($tenant, $data): AccountTransaction {
            $account = FinancialAccount::where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($data['financial_account_id']);

            return $this->recordAccountTransaction($tenant, $account, 'deposit', 'in', round((float) $data['amount'], 2), $data);
        });

        return redirect()->route('module', ['module' => 'finance', 'section' => 'deposits'])->with('status', 'Dépôt '.$transaction->number.' enregistré.');
    }

    public function storeMoneyTransfer(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $data = $request->validate([
            'from_account_id' => ['required', 'integer', Rule::exists('financial_accounts', 'id')->where('tenant_id', $tenant->id)],
            'to_account_id' => ['required', 'integer', 'different:from_account_id', Rule::exists('financial_accounts', 'id')->where('tenant_id', $tenant->id)],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reference' => ['nullable', 'string', 'max:160'],
            'note' => ['nullable', 'string', 'max:700'],
            'transacted_at' => ['nullable', 'date'],
        ]);

        DB::transaction(function () use ($tenant, $data): void {
            $accounts = FinancialAccount::where('tenant_id', $tenant->id)
                ->whereIn('id', [$data['from_account_id'], $data['to_account_id']])
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $from = $accounts->get((int) $data['from_account_id']);
            $to = $accounts->get((int) $data['to_account_id']);
            abort_unless($from && $to, 404);

            $amount = round((float) $data['amount'], 2);
            $out = $this->recordAccountTransaction($tenant, $from, 'transfer', 'out', $amount, $data + ['related_account_id' => $to->id]);
            $this->recordAccountTransaction($tenant, $to, 'transfer', 'in', $amount, $data + ['related_account_id' => $from->id, 'transfer_pair' => $out->number]);
        });

        return redirect()->route('module', ['module' => 'finance', 'section' => 'transfers'])->with('status', 'Transfert enregistré.');
    }

    public function storeCustomerAdvance(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $data = $request->validate([
            'contact_id' => ['required', 'integer', Rule::exists('contacts', 'id')->where('tenant_id', $tenant->id)->where('kind', 'client')],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999'],
            'payment_method' => ['required', 'in:cash,card,transfer,cheque,other'],
            'paid_at' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:700'],
        ]);

        $advance = DB::transaction(function () use ($tenant, $data): CustomerAdvance {
            $contact = Contact::where('tenant_id', $tenant->id)
                ->where('kind', 'client')
                ->lockForUpdate()
                ->findOrFail($data['contact_id']);

            $amount = round((float) $data['amount'], 2);
            $advance = CustomerAdvance::create([
                'tenant_id' => $tenant->id,
                'contact_id' => $contact->id,
                'number' => $this->nextCustomerAdvanceNumber($tenant),
                'amount' => $amount,
                'payment_method' => $data['payment_method'],
                'reference' => $data['reference'] ?? null,
                'note' => $data['note'] ?? null,
                'status' => 'active',
                'paid_at' => $data['paid_at'] ?? now(),
                'metadata' => ['source' => 'manual'],
            ]);

            $contact->increment('advance_balance', $amount);

            return $advance;
        });

        return redirect()
            ->route('module', ['module' => 'finance', 'section' => 'advances'])
            ->with('status', 'Avance '.$advance->number.' ajoutée au solde client.');
    }

    public function destroyCustomerAdvance(CustomerAdvance $advance): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless((int) $advance->tenant_id === (int) $tenant->id, 404);

        try {
            DB::transaction(function () use ($advance): void {
                $advance->refresh();
                if ($advance->status !== 'active') {
                    return;
                }

                $contact = Contact::whereKey($advance->contact_id)->lockForUpdate()->firstOrFail();
                if ((float) $contact->advance_balance < (float) $advance->amount) {
                    throw new \RuntimeException('Cette avance est déjà utilisée partiellement. Impossible de l’annuler sans rendre le solde négatif.');
                }

                $contact->decrement('advance_balance', (float) $advance->amount);
                $advance->update([
                    'status' => 'voided',
                    'metadata' => array_merge($advance->metadata ?? [], ['voided_at' => now()->toISOString()]),
                ]);
            });
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['advance' => $exception->getMessage()]);
        }

        return back()->with('status', 'Avance annulée et solde client ajusté.');
    }

    public function storeExpenseCategory(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('expense_categories', 'name')->where('tenant_id', $tenant->id)],
            'color' => ['nullable', 'string', 'max:16'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        ExpenseCategory::create([
            'tenant_id' => $tenant->id,
            'name' => $data['name'],
            'color' => $data['color'] ?? '#4F46E5',
            'description' => $data['description'] ?? null,
        ]);

        return redirect()
            ->route('module', ['module' => 'finance', 'section' => 'expense-categories'])
            ->with('status', 'Catégorie de dépense ajoutée.');
    }

    public function storePurchase(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $data = $request->validate([
            'supplier_id' => ['required', 'integer', Rule::exists('contacts', 'id')->where('tenant_id', $tenant->id)->where('kind', 'supplier')],
            'ordered_at' => ['nullable', 'date'],
            'expected_at' => ['nullable', 'date'],
            'supplier_invoice' => ['nullable', 'string', 'max:120'],
            'reference' => ['nullable', 'string', 'max:120'],
            'warehouse' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:500'],
            'status' => ['required', 'in:ordered,received,draft'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['nullable', 'integer', Rule::exists('items', 'id')->where('tenant_id', $tenant->id)],
            'items.*.quantity' => ['nullable', 'integer', 'min:1'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        $lines = collect($data['items'])
            ->map(fn (array $line) => [
                'item_id' => (int) ($line['item_id'] ?? 0),
                'quantity' => max(0, (int) ($line['quantity'] ?? 0)),
                'unit_cost' => round(max(0, (float) ($line['unit_cost'] ?? 0)), 2),
            ])
            ->filter(fn (array $line) => $line['item_id'] > 0 && $line['quantity'] > 0)
            ->values();

        if ($lines->isEmpty()) {
            return back()->withErrors(['items' => 'Ajoutez au moins un article à la commande.'])->withInput();
        }

        $purchase = DB::transaction(function () use ($tenant, $data, $lines): Purchase {
            $receiveNow = $data['status'] === 'received';
            $items = Item::where('tenant_id', $tenant->id)
                ->whereIn('id', $lines->pluck('item_id'))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $total = $lines->sum(fn (array $line) => round($line['quantity'] * $line['unit_cost'], 2));
            $purchase = Purchase::create([
                'tenant_id' => $tenant->id,
                'supplier_id' => $data['supplier_id'],
                'number' => $this->nextPurchaseNumber($tenant),
                'status' => $receiveNow ? 'received' : $data['status'],
                'total_amount' => $total,
                'ordered_at' => $data['ordered_at'] ?? now()->toDateString(),
                'expected_at' => $data['expected_at'] ?? null,
                'received_at' => $receiveNow ? now()->toDateString() : null,
                'metadata' => [
                    'supplier_invoice' => $data['supplier_invoice'] ?? null,
                    'reference' => $data['reference'] ?? null,
                    'warehouse' => $data['warehouse'] ?? null,
                    'note' => $data['note'] ?? null,
                ],
            ]);

            foreach ($lines as $line) {
                $item = $items->get($line['item_id']);
                if (! $item) {
                    throw new \RuntimeException('Un article de la commande est introuvable.');
                }

                $purchase->items()->create([
                    'item_id' => $item->id,
                    'quantity_ordered' => $line['quantity'],
                    'quantity_received' => $receiveNow ? $line['quantity'] : 0,
                    'unit_cost' => $line['unit_cost'],
                ]);

                if ($receiveNow && $item->type !== 'service') {
                    $item->increment('stock_quantity', $line['quantity']);
                    $item->update([
                        'purchase_price' => $line['unit_cost'],
                        'status' => 'active',
                    ]);
                }
            }

            return $purchase;
        });

        return redirect()
            ->route('module', ['module' => 'purchases', 'section' => 'list'])
            ->with('status', 'Achat '.$purchase->number.' enregistré.');
    }

    public function receivePurchase(Purchase $purchase): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless($purchase->tenant_id === $tenant->id, 404);

        DB::transaction(function () use ($purchase): void {
            $purchase->load('items.item');
            foreach ($purchase->items as $line) {
                $missing = max(0, $line->quantity_ordered - $line->quantity_received);
                if ($missing <= 0 || ! $line->item) {
                    continue;
                }

                if ($line->item->type !== 'service') {
                    $line->item->increment('stock_quantity', $missing);
                    $line->item->update([
                        'purchase_price' => $line->unit_cost,
                        'status' => 'active',
                    ]);
                }
                $line->increment('quantity_received', $missing);
            }

            $purchase->update([
                'status' => 'received',
                'received_at' => now()->toDateString(),
            ]);
        });

        return back()->with('status', 'Achat '.$purchase->number.' réceptionné.');
    }

    public function storePurchaseReturn(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $data = $request->validate([
            'purchase_id' => ['required', 'integer', Rule::exists('purchases', 'id')->where('tenant_id', $tenant->id)],
            'returned_at' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['nullable', 'integer', Rule::exists('items', 'id')->where('tenant_id', $tenant->id)],
            'items.*.quantity' => ['nullable', 'integer', 'min:1'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        $lines = collect($data['items'])
            ->map(fn (array $line) => [
                'item_id' => (int) ($line['item_id'] ?? 0),
                'quantity' => max(0, (int) ($line['quantity'] ?? 0)),
                'unit_cost' => round(max(0, (float) ($line['unit_cost'] ?? 0)), 2),
            ])
            ->filter(fn (array $line) => $line['item_id'] > 0 && $line['quantity'] > 0)
            ->values();

        if ($lines->isEmpty()) {
            return back()->withErrors(['items' => 'Ajoutez au moins un article à retourner.'])->withInput();
        }

        $return = DB::transaction(function () use ($tenant, $data, $lines): PurchaseReturn {
            $purchase = Purchase::where('tenant_id', $tenant->id)->whereKey($data['purchase_id'])->lockForUpdate()->firstOrFail();
            $items = Item::where('tenant_id', $tenant->id)
                ->whereIn('id', $lines->pluck('item_id'))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $payloadLines = [];
            $total = 0.0;
            foreach ($lines as $line) {
                $item = $items->get($line['item_id']);
                if (! $item) {
                    throw new \RuntimeException('Un article du retour est introuvable.');
                }

                $lineTotal = round($line['quantity'] * $line['unit_cost'], 2);
                $total += $lineTotal;
                $payloadLines[] = [
                    'item_id' => $item->id,
                    'name' => $item->title,
                    'quantity' => $line['quantity'],
                    'unit_cost' => $line['unit_cost'],
                    'total_price' => $lineTotal,
                ];

                if ($item->type !== 'service') {
                    $item->decrement('stock_quantity', $line['quantity']);
                }
            }

            return PurchaseReturn::create([
                'tenant_id' => $tenant->id,
                'purchase_id' => $purchase->id,
                'supplier_id' => $purchase->supplier_id,
                'number' => $this->nextPurchaseReturnNumber($tenant),
                'status' => 'completed',
                'total_amount' => round($total, 2),
                'returned_at' => ! empty($data['returned_at']) ? Carbon::parse($data['returned_at']) : now(),
                'reason' => $data['reason'] ?? null,
                'lines' => $payloadLines,
            ]);
        });

        return redirect()
            ->route('module', ['module' => 'purchases', 'section' => 'returns'])
            ->with('status', 'Retour achat '.$return->number.' enregistré.');
    }

    public function module(Request $request, string $module): View
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

        $section = $request->query('section', 'list');
        $sales = $this->salesListQuery($tenant, $request)->paginate(25)->withQueryString();
        $quotations = $this->quotationsQuery($tenant, $request)->paginate(25, ['*'], 'quotes_page')->withQueryString();
        $salesTotals = (clone $this->salesListQuery($tenant, $request))
            ->get()
            ->reduce(function (array $carry, Sale $sale): array {
                $paid = $this->salePaidAmount($sale);
                $carry['total'] += (float) $sale->total_amount;
                $carry['paid'] += $paid;
                $carry['due'] += max(0, (float) $sale->total_amount - $paid);

                return $carry;
            }, ['total' => 0.0, 'paid' => 0.0, 'due' => 0.0]);
        $purchaseList = $this->purchasesQuery($tenant, $request)->paginate(25, ['*'], 'purchases_page')->withQueryString();
        $purchaseReturns = $this->purchaseReturnsQuery($tenant, $request)->paginate(25, ['*'], 'purchase_returns_page')->withQueryString();
        $expenses = $this->expensesQuery($tenant, $request)->paginate(25, ['*'], 'expenses_page')->withQueryString();
        $expenseCategories = $this->expenseCategoriesQuery($tenant, $request)->get();
        $customerAdvances = $this->customerAdvancesQuery($tenant, $request)->paginate(25, ['*'], 'advances_page')->withQueryString();
        $financialAccounts = FinancialAccount::where('tenant_id', $tenant->id)->orderByDesc('is_active')->orderBy('name')->get();
        $accountTransactions = $this->accountTransactionsQuery($tenant, $request)->paginate(25, ['*'], 'account_transactions_page')->withQueryString();
        $reportContext = $this->reportContext($tenant, $request);
        $editContact = null;
        if ($module === 'contacts' && $request->filled('edit')) {
            $editContact = Contact::where('tenant_id', $tenant->id)->whereKey((int) $request->query('edit'))->first();
        }

        return view('librairepro.module', [
            'tenant' => $tenant,
            'active' => $modules[$module]['active'],
            'module' => $module,
            'section' => $section,
            'meta' => $modules[$module],
            'sales' => $module === 'sales' ? $sales : $tenant->sales()->with('contact')->latest('sold_at')->take(8)->get(),
            'salesTotals' => $salesTotals,
            'salesClients' => Contact::where('tenant_id', $tenant->id)->where('kind', 'client')->orderBy('name')->get(),
            'quotations' => $quotations,
            'quoteItems' => Item::where('tenant_id', $tenant->id)->where('status', 'active')->orderBy('title')->take(350)->get(),
            'paymentSales' => $tenant->sales()->with('contact')->latest('sold_at')->take(80)->get(),
            'salePayments' => $this->salePaymentsQuery($tenant, $request)->paginate(25, ['*'], 'payments_page')->withQueryString(),
            'saleReturns' => $this->saleReturnsQuery($tenant, $request)->paginate(25, ['*'], 'returns_page')->withQueryString(),
            'deliveryOrders' => $this->deliveryOrdersQuery($tenant, $request)->paginate(25, ['*'], 'deliveries_page')->withQueryString(),
            'deliverySales' => $tenant->sales()->with('contact')->whereDoesntHave('deliveryOrders')->latest('sold_at')->take(80)->get(),
            'purchases' => $module === 'purchases' ? $purchaseList : Purchase::where('tenant_id', $tenant->id)->with('supplier')->latest()->take(8)->get(),
            'purchaseReturns' => $purchaseReturns,
            'purchaseSuppliers' => Contact::where('tenant_id', $tenant->id)->where('kind', 'supplier')->orderBy('name')->get(),
            'purchaseItems' => Item::where('tenant_id', $tenant->id)->where('status', 'active')->orderBy('title')->take(300)->get(),
            'purchaseReturnSources' => Purchase::where('tenant_id', $tenant->id)->with(['supplier', 'items.item'])->whereIn('status', ['received', 'partially_received'])->latest('received_at')->take(80)->get(),
            'loans' => Loan::where('tenant_id', $tenant->id)->with(['member', 'item'])->latest()->take(8)->get(),
            'contacts' => Contact::where('tenant_id', $tenant->id)->orderBy('kind')->orderBy('name')->take(12)->get(),
            'contactStats' => [
                'clients' => Contact::where('tenant_id', $tenant->id)->where('kind', 'client')->count(),
                'suppliers' => Contact::where('tenant_id', $tenant->id)->where('kind', 'supplier')->count(),
                'receivable' => Contact::where('tenant_id', $tenant->id)->where('kind', 'client')->sum('outstanding_balance'),
                'advances' => Contact::where('tenant_id', $tenant->id)->where('kind', 'client')->sum('advance_balance'),
                'supplier_previous' => Contact::where('tenant_id', $tenant->id)->where('kind', 'supplier')->sum('opening_balance'),
                'supplier_purchases' => Purchase::where('tenant_id', $tenant->id)->where('status', '!=', 'cancelled')->sum('total_amount'),
                'supplier_returns' => PurchaseReturn::where('tenant_id', $tenant->id)->where('status', 'completed')->sum('total_amount'),
            ],
            'editContact' => $editContact,
            'expenses' => $module === 'finance' ? $expenses : Expense::where('tenant_id', $tenant->id)->latest('spent_at')->take(8)->get(),
            'expenseCategories' => $expenseCategories,
            'expenseTotals' => [
                'month' => Expense::where('tenant_id', $tenant->id)->whereDate('spent_at', '>=', now()->startOfMonth())->sum('amount'),
                'page' => $expenses->sum('amount'),
                'categories' => $expenseCategories->count(),
            ],
            'financeClients' => Contact::where('tenant_id', $tenant->id)->where('kind', 'client')->orderBy('name')->get(),
            'customerAdvances' => $customerAdvances,
            'advanceStats' => [
                'balance' => Contact::where('tenant_id', $tenant->id)->where('kind', 'client')->sum('advance_balance'),
                'month' => CustomerAdvance::where('tenant_id', $tenant->id)->where('status', 'active')->whereDate('paid_at', '>=', now()->startOfMonth())->sum('amount'),
                'active_count' => CustomerAdvance::where('tenant_id', $tenant->id)->where('status', 'active')->count(),
                'page' => $customerAdvances->sum('amount'),
            ],
            'financialAccounts' => $financialAccounts,
            'accountTransactions' => $accountTransactions,
            'accountStats' => [
                'balance' => $financialAccounts->sum('current_balance'),
                'active' => $financialAccounts->where('is_active', true)->count(),
                'cash' => $financialAccounts->where('type', 'cash')->sum('current_balance'),
                'bank' => $financialAccounts->where('type', 'bank')->sum('current_balance'),
                'deposits_month' => AccountTransaction::where('tenant_id', $tenant->id)->where('type', 'deposit')->whereDate('transacted_at', '>=', now()->startOfMonth())->sum('amount'),
                'cash_movements' => AccountTransaction::where('tenant_id', $tenant->id)->whereHas('account', fn (Builder $builder) => $builder->where('type', 'cash'))->count(),
            ],
            'reportContext' => $reportContext,
            'settingsUsers' => $tenant->users()->orderBy('name')->get(),
            'settingsRoles' => Role::where('tenant_id', $tenant->id)->orderBy('name')->get(),
            'permissionCatalog' => $this->permissionCatalog(),
            'settingsTaxes' => Tax::where('tenant_id', $tenant->id)->orderBy('name')->get(),
            'settingsUnits' => Unit::where('tenant_id', $tenant->id)->orderBy('name')->get(),
            'settingsTaxGroups' => $this->settingsRecords($tenant, 'tax_groups'),
            'paymentTypes' => $this->settingsRecords($tenant, 'payment_types'),
            'countries' => $this->settingsRecords($tenant, 'countries'),
            'states' => $this->settingsRecords($tenant, 'states'),
            'stores' => $this->storeCatalog($tenant),
            'currentStore' => $this->currentStore($tenant),
            'storeAccessOptions' => $this->storeAccessOptions($tenant),
            'auditLogs' => AuditLog::where('tenant_id', $tenant->id)->with('user')->latest()->take(12)->get(),
        ]);
    }

    private function normalizedCartLines($cart, bool $priceEditable = false): \Illuminate\Support\Collection
    {
        return collect($cart)->map(function ($line) use ($priceEditable) {
            return [
                'item_id' => (int) ($line['id'] ?? $line['item_id'] ?? 0),
                'quantity' => max(1, (int) ($line['quantity'] ?? 1)),
                'unit_price' => $priceEditable && array_key_exists('price', $line)
                    ? round(max(0, (float) $line['price']), 2)
                    : ($priceEditable && array_key_exists('unit_price', $line) ? round(max(0, (float) $line['unit_price']), 2) : null),
                'original_price' => array_key_exists('original_price', $line) ? round(max(0, (float) $line['original_price']), 2) : null,
                'note' => mb_substr(trim((string) ($line['note'] ?? '')), 0, 160),
            ];
        })->filter(fn ($line) => $line['item_id'] > 0)
            ->values();
    }

    private function reportContext(Tenant $tenant, Request $request): array
    {
        $from = $request->query('from', now()->startOfMonth()->toDateString());
        $to = $request->query('to', now()->toDateString());
        $q = trim((string) $request->query('q'));

        $salesQuery = Sale::query()
            ->with(['contact', 'items.item', 'payments'])
            ->where('tenant_id', $tenant->id)
            ->when($from, fn (Builder $builder) => $builder->whereDate('sold_at', '>=', $from))
            ->when($to, fn (Builder $builder) => $builder->whereDate('sold_at', '<=', $to))
            ->when($request->query('customer_id'), fn (Builder $builder, $customer) => $builder->where('contact_id', $customer))
            ->when($q !== '', fn (Builder $builder) => $builder->where(fn (Builder $nested) => $nested
                ->where('number', 'like', "%{$q}%")
                ->orWhere('payment_method', 'like', "%{$q}%")
                ->orWhereHas('contact', fn (Builder $contact) => $contact->where('name', 'like', "%{$q}%"))));

        $sales = $salesQuery->latest('sold_at')->get();
        $saleIds = $sales->pluck('id');
        $saleItems = \App\Models\SaleItem::query()
            ->with('item.category')
            ->whereIn('sale_id', $saleIds)
            ->get();

        $purchaseQuery = Purchase::query()
            ->with('supplier')
            ->where('tenant_id', $tenant->id)
            ->when($from, fn (Builder $builder) => $builder->whereDate('ordered_at', '>=', $from))
            ->when($to, fn (Builder $builder) => $builder->whereDate('ordered_at', '<=', $to));
        $purchases = $purchaseQuery->latest('ordered_at')->get();

        $expenses = Expense::query()
            ->where('tenant_id', $tenant->id)
            ->when($from, fn (Builder $builder) => $builder->whereDate('spent_at', '>=', $from))
            ->when($to, fn (Builder $builder) => $builder->whereDate('spent_at', '<=', $to))
            ->latest('spent_at')
            ->get();

        $saleReturns = SaleReturn::query()
            ->with('sale.contact')
            ->where('tenant_id', $tenant->id)
            ->when($from, fn (Builder $builder) => $builder->whereDate('returned_at', '>=', $from))
            ->when($to, fn (Builder $builder) => $builder->whereDate('returned_at', '<=', $to))
            ->latest('returned_at')
            ->get();

        $purchaseReturns = PurchaseReturn::query()
            ->with(['purchase', 'supplier'])
            ->where('tenant_id', $tenant->id)
            ->when($from, fn (Builder $builder) => $builder->whereDate('returned_at', '>=', $from))
            ->when($to, fn (Builder $builder) => $builder->whereDate('returned_at', '<=', $to))
            ->latest('returned_at')
            ->get();

        $payments = SalePayment::query()
            ->with(['sale', 'contact'])
            ->where('tenant_id', $tenant->id)
            ->when($from, fn (Builder $builder) => $builder->whereDate('paid_at', '>=', $from))
            ->when($to, fn (Builder $builder) => $builder->whereDate('paid_at', '<=', $to))
            ->latest('paid_at')
            ->get();

        $stockItems = Item::query()
            ->with(['category', 'brand'])
            ->where('tenant_id', $tenant->id)
            ->orderBy('title')
            ->get();

        $purchaseCost = $saleItems->sum(fn ($line) => (float) ($line->item?->purchase_price ?? 0) * (int) $line->quantity);
        $grossRevenue = (float) $sales->sum('total_amount');
        $returnsAmount = (float) $saleReturns->sum('total_amount');
        $netRevenue = max(0, $grossRevenue - $returnsAmount);
        $expenseTotal = (float) $expenses->sum('amount');
        $grossProfit = $netRevenue - $purchaseCost;

        $topItems = $saleItems->groupBy('item_id')->map(function ($lines) {
            $first = $lines->first();

            return [
                'name' => $first->name,
                'quantity' => (int) $lines->sum('quantity'),
                'revenue' => (float) $lines->sum('total_price'),
                'cost' => (float) $lines->sum(fn ($line) => (float) ($line->item?->purchase_price ?? 0) * (int) $line->quantity),
            ];
        })->sortByDesc('revenue')->values();

        $categorySales = $saleItems->groupBy(fn ($line) => $line->item?->category?->name ?? 'Sans catégorie')->map(fn ($lines, $name) => [
            'name' => $name,
            'quantity' => (int) $lines->sum('quantity'),
            'revenue' => (float) $lines->sum('total_price'),
        ])->sortByDesc('revenue')->values();

        return [
            'from' => $from,
            'to' => $to,
            'sales' => $sales,
            'purchases' => $purchases,
            'expenses' => $expenses,
            'saleReturns' => $saleReturns,
            'purchaseReturns' => $purchaseReturns,
            'payments' => $payments,
            'stockItems' => $stockItems,
            'topItems' => $topItems,
            'categorySales' => $categorySales,
            'clients' => Contact::where('tenant_id', $tenant->id)->where('kind', 'client')->orderBy('name')->get(),
            'summary' => [
                'gross_revenue' => $grossRevenue,
                'returns' => $returnsAmount,
                'net_revenue' => $netRevenue,
                'purchase_cost' => $purchaseCost,
                'gross_profit' => $grossProfit,
                'expenses' => $expenseTotal,
                'net_profit' => $grossProfit - $expenseTotal,
                'purchases' => (float) $purchases->sum('total_amount'),
                'payments' => (float) $payments->sum('amount'),
                'stock_value' => (float) $stockItems->sum(fn ($item) => (float) $item->stock_quantity * (float) $item->purchase_price),
            ],
        ];
    }

    private function cartTotals(Tenant $tenant, \Illuminate\Support\Collection $lineItems, float $discount = 0): array
    {
        $items = Item::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('id', $lineItems->pluck('item_id'))
            ->get()
            ->keyBy('id');

        $subtotal = 0.0;
        foreach ($lineItems as $line) {
            $item = $items->get($line['item_id']);
            if (! $item || $item->status !== 'active') {
                throw new \RuntimeException('Un article du panier est indisponible.');
            }

            $unitPrice = $line['unit_price'] ?? (float) $item->sale_price;
            $subtotal += round($unitPrice * $line['quantity'], 2);
        }

        $discount = min(max(0, round($discount, 2)), $subtotal);
        $total = max(0, round($subtotal - $discount, 2));

        return [
            'subtotal' => round($subtotal, 2),
            'discount' => $discount,
            'tax' => round($total * 0.2 / 1.2, 2),
            'total' => $total,
        ];
    }

    private function nextSaleNumber(Tenant $tenant): string
    {
        $max = Sale::where('tenant_id', $tenant->id)
            ->where('number', 'like', 'BL%')
            ->pluck('number')
            ->map(fn ($number) => (int) preg_replace('/\D+/', '', (string) $number))
            ->max();

        if (! $max) {
            $max = Sale::where('tenant_id', $tenant->id)->count();
        }

        return 'BL'.($max + 1);
    }

    private function nextTicketNumber(Tenant $tenant): string
    {
        $max = PosTicket::where('tenant_id', $tenant->id)
            ->where('number', 'like', 'ATT%')
            ->pluck('number')
            ->map(fn ($number) => (int) preg_replace('/\D+/', '', (string) $number))
            ->max() ?? 0;

        return 'ATT'.str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    private function nextPaymentNumber(Tenant $tenant): string
    {
        $max = SalePayment::where('tenant_id', $tenant->id)
            ->where('number', 'like', 'PAY%')
            ->pluck('number')
            ->map(fn ($number) => (int) preg_replace('/\D+/', '', (string) $number))
            ->max() ?? 0;

        return 'PAY'.str_pad((string) ($max + 1), 5, '0', STR_PAD_LEFT);
    }

    private function nextReturnNumber(Tenant $tenant): string
    {
        $max = SaleReturn::where('tenant_id', $tenant->id)
            ->where('number', 'like', 'RET%')
            ->pluck('number')
            ->map(fn ($number) => (int) preg_replace('/\D+/', '', (string) $number))
            ->max() ?? 0;

        return 'RET'.str_pad((string) ($max + 1), 5, '0', STR_PAD_LEFT);
    }

    private function nextDeliveryNumber(Tenant $tenant): string
    {
        $max = DeliveryOrder::where('tenant_id', $tenant->id)
            ->where('number', 'like', 'LIV%')
            ->pluck('number')
            ->map(fn ($number) => (int) preg_replace('/\D+/', '', (string) $number))
            ->max() ?? 0;

        return 'LIV'.str_pad((string) ($max + 1), 5, '0', STR_PAD_LEFT);
    }

    private function nextPurchaseNumber(Tenant $tenant): string
    {
        $max = Purchase::where('tenant_id', $tenant->id)
            ->where('number', 'like', 'ACH%')
            ->pluck('number')
            ->map(fn ($number) => (int) preg_replace('/\D+/', '', (string) $number))
            ->max() ?? 0;

        return 'ACH'.str_pad((string) ($max + 1), 5, '0', STR_PAD_LEFT);
    }

    private function nextPurchaseReturnNumber(Tenant $tenant): string
    {
        $max = PurchaseReturn::where('tenant_id', $tenant->id)
            ->where('number', 'like', 'RAC%')
            ->pluck('number')
            ->map(fn ($number) => (int) preg_replace('/\D+/', '', (string) $number))
            ->max() ?? 0;

        return 'RAC'.str_pad((string) ($max + 1), 5, '0', STR_PAD_LEFT);
    }

    private function nextQuotationNumber(Tenant $tenant): string
    {
        $max = Quotation::where('tenant_id', $tenant->id)
            ->where('number', 'like', 'DEV%')
            ->pluck('number')
            ->map(fn ($number) => (int) preg_replace('/\D+/', '', (string) $number))
            ->max() ?? 0;

        return 'DEV'.str_pad((string) ($max + 1), 5, '0', STR_PAD_LEFT);
    }

    private function nextExpenseNumber(Tenant $tenant): string
    {
        $max = Expense::where('tenant_id', $tenant->id)
            ->where('number', 'like', 'DEP%')
            ->pluck('number')
            ->map(fn ($number) => (int) preg_replace('/\D+/', '', (string) $number))
            ->max() ?? 0;

        return 'DEP'.str_pad((string) ($max + 1), 5, '0', STR_PAD_LEFT);
    }

    private function nextAccountTransactionNumber(Tenant $tenant): string
    {
        $max = AccountTransaction::where('tenant_id', $tenant->id)
            ->where('number', 'like', 'ACC%')
            ->pluck('number')
            ->map(fn ($number) => (int) preg_replace('/\D+/', '', (string) $number))
            ->max() ?? 0;

        return 'ACC'.str_pad((string) ($max + 1), 6, '0', STR_PAD_LEFT);
    }

    private function recordAccountTransaction(Tenant $tenant, FinancialAccount $account, string $type, string $direction, float $amount, array $data = []): AccountTransaction
    {
        $signedAmount = $direction === 'out' ? -abs($amount) : abs($amount);
        $account->increment('current_balance', $signedAmount);
        $account->refresh();

        return AccountTransaction::create([
            'tenant_id' => $tenant->id,
            'financial_account_id' => $account->id,
            'related_account_id' => $data['related_account_id'] ?? null,
            'number' => $this->nextAccountTransactionNumber($tenant),
            'type' => $type,
            'direction' => $direction,
            'amount' => abs($amount),
            'balance_after' => $account->current_balance,
            'payment_method' => $data['payment_method'] ?? null,
            'reference' => $data['reference'] ?? null,
            'note' => $data['note'] ?? null,
            'transacted_at' => $data['transacted_at'] ?? now(),
            'metadata' => collect($data)->only(['transfer_pair'])->filter()->all(),
        ]);
    }

    private function nextStockAdjustmentNumber(Tenant $tenant): string
    {
        $max = StockAdjustment::where('tenant_id', $tenant->id)
            ->where('number', 'like', 'AJS%')
            ->pluck('number')
            ->map(fn ($number) => (int) preg_replace('/\D+/', '', (string) $number))
            ->max() ?? 0;

        return 'AJS'.str_pad((string) ($max + 1), 5, '0', STR_PAD_LEFT);
    }

    private function nextStockTransferNumber(Tenant $tenant): string
    {
        $max = StockTransfer::where('tenant_id', $tenant->id)
            ->where('number', 'like', 'TRS%')
            ->pluck('number')
            ->map(fn ($number) => (int) preg_replace('/\D+/', '', (string) $number))
            ->max() ?? 0;

        return 'TRS'.str_pad((string) ($max + 1), 5, '0', STR_PAD_LEFT);
    }

    private function nextCustomerAdvanceNumber(Tenant $tenant): string
    {
        $max = CustomerAdvance::where('tenant_id', $tenant->id)
            ->where('number', 'like', 'AVC%')
            ->pluck('number')
            ->map(fn ($number) => (int) preg_replace('/\D+/', '', (string) $number))
            ->max() ?? 0;

        return 'AVC'.str_pad((string) ($max + 1), 5, '0', STR_PAD_LEFT);
    }

    private function nextContactCode(Tenant $tenant, string $kind): string
    {
        $prefix = $kind === 'supplier' ? 'FR' : 'CL';
        $max = Contact::where('tenant_id', $tenant->id)
            ->where('kind', $kind)
            ->where('code', 'like', $prefix.'%')
            ->pluck('code')
            ->map(fn ($code) => (int) preg_replace('/\D+/', '', (string) $code))
            ->max() ?? 0;

        return $prefix.str_pad((string) ($max + 1), 5, '0', STR_PAD_LEFT);
    }

    private function salesListQuery(Tenant $tenant, Request $request): Builder
    {
        $query = trim((string) $request->query('q'));
        $from = $request->query('from');
        $to = $request->query('to');
        $client = $request->query('client');
        $paymentStatus = $request->query('payment_status');
        $paymentMethod = $request->query('payment_method');
        $minTotal = $request->query('min_total');
        $maxTotal = $request->query('max_total');

        return Sale::query()
            ->with(['contact', 'items'])
            ->where('tenant_id', $tenant->id)
            ->when($query !== '', function (Builder $builder) use ($query): void {
                $builder->where(function (Builder $builder) use ($query): void {
                    $builder->where('number', 'like', "%{$query}%")
                        ->orWhere('payment_method', 'like', "%{$query}%")
                        ->orWhere('metadata->invoice_number', 'like', "%{$query}%")
                        ->orWhere('metadata->reference_number', 'like', "%{$query}%")
                        ->orWhereHas('contact', fn (Builder $contactQuery) => $contactQuery->where('name', 'like', "%{$query}%"));
                });
            })
            ->when($from, fn (Builder $builder) => $builder->whereDate('sold_at', '>=', $from))
            ->when($to, fn (Builder $builder) => $builder->whereDate('sold_at', '<=', $to))
            ->when($client, fn (Builder $builder) => $builder->where('contact_id', $client))
            ->when(in_array($paymentMethod, ['cash', 'card', 'transfer', 'advance', 'mixed'], true), function (Builder $builder) use ($paymentMethod): void {
                if ($paymentMethod === 'mixed') {
                    $builder->where('payment_method', 'like', '%+%');
                } else {
                    $builder->where('payment_method', 'like', "%{$paymentMethod}%");
                }
            })
            ->when(is_numeric($minTotal), fn (Builder $builder) => $builder->where('total_amount', '>=', (float) $minTotal))
            ->when(is_numeric($maxTotal), fn (Builder $builder) => $builder->where('total_amount', '<=', (float) $maxTotal))
            ->when(in_array($paymentStatus, ['paid', 'partial', 'unpaid', 'refunded'], true), function (Builder $builder) use ($paymentStatus): void {
                if ($paymentStatus === 'paid') {
                    $builder->where('status', 'paid');
                } elseif ($paymentStatus === 'unpaid') {
                    $builder->where('status', 'unpaid');
                } elseif ($paymentStatus === 'refunded') {
                    $builder->where('status', 'refunded');
                } else {
                    $builder->where('status', 'partial');
                }
            })
            ->latest('sold_at');
    }

    private function salePaymentsQuery(Tenant $tenant, Request $request): Builder
    {
        $query = trim((string) $request->query('q'));

        return SalePayment::query()
            ->with(['sale', 'contact'])
            ->where('tenant_id', $tenant->id)
            ->when($query !== '', function (Builder $builder) use ($query): void {
                $builder->where(function (Builder $builder) use ($query): void {
                    $builder->where('number', 'like', "%{$query}%")
                        ->orWhere('method', 'like', "%{$query}%")
                        ->orWhere('reference', 'like', "%{$query}%")
                        ->orWhereHas('sale', fn (Builder $saleQuery) => $saleQuery->where('number', 'like', "%{$query}%"))
                        ->orWhereHas('contact', fn (Builder $contactQuery) => $contactQuery->where('name', 'like', "%{$query}%"));
                });
            })
            ->when($request->query('payment_method'), fn (Builder $builder, $method) => $builder->where('method', $method))
            ->when($request->query('from'), fn (Builder $builder, $from) => $builder->whereDate('paid_at', '>=', $from))
            ->when($request->query('to'), fn (Builder $builder, $to) => $builder->whereDate('paid_at', '<=', $to))
            ->latest('paid_at');
    }

    private function saleReturnsQuery(Tenant $tenant, Request $request): Builder
    {
        $query = trim((string) $request->query('q'));

        return SaleReturn::query()
            ->with(['sale', 'contact'])
            ->where('tenant_id', $tenant->id)
            ->when($query !== '', function (Builder $builder) use ($query): void {
                $builder->where(function (Builder $builder) use ($query): void {
                    $builder->where('number', 'like', "%{$query}%")
                        ->orWhere('reason', 'like', "%{$query}%")
                        ->orWhereHas('sale', fn (Builder $saleQuery) => $saleQuery->where('number', 'like', "%{$query}%"))
                        ->orWhereHas('contact', fn (Builder $contactQuery) => $contactQuery->where('name', 'like', "%{$query}%"));
                });
            })
            ->when($request->query('return_status'), fn (Builder $builder, $status) => $builder->where('status', $status))
            ->when($request->query('refund_method'), fn (Builder $builder, $method) => $builder->where('refund_method', $method))
            ->when($request->query('from'), fn (Builder $builder, $from) => $builder->whereDate('returned_at', '>=', $from))
            ->when($request->query('to'), fn (Builder $builder, $to) => $builder->whereDate('returned_at', '<=', $to))
            ->latest('returned_at');
    }

    private function deliveryOrdersQuery(Tenant $tenant, Request $request): Builder
    {
        $query = trim((string) $request->query('q'));

        return DeliveryOrder::query()
            ->with(['sale', 'contact'])
            ->where('tenant_id', $tenant->id)
            ->when($query !== '', function (Builder $builder) use ($query): void {
                $builder->where(function (Builder $builder) use ($query): void {
                    $builder->where('number', 'like', "%{$query}%")
                        ->orWhere('assigned_to', 'like', "%{$query}%")
                        ->orWhere('delivery_address', 'like', "%{$query}%")
                        ->orWhereHas('sale', fn (Builder $saleQuery) => $saleQuery->where('number', 'like', "%{$query}%"))
                        ->orWhereHas('contact', fn (Builder $contactQuery) => $contactQuery->where('name', 'like', "%{$query}%"));
                });
            })
            ->when($request->query('delivery_status'), fn (Builder $builder, $status) => $builder->where('status', $status))
            ->when($request->query('from'), fn (Builder $builder, $from) => $builder->whereDate('scheduled_at', '>=', $from))
            ->when($request->query('to'), fn (Builder $builder, $to) => $builder->whereDate('scheduled_at', '<=', $to))
            ->latest();
    }

    private function quotationsQuery(Tenant $tenant, Request $request): Builder
    {
        $query = trim((string) $request->query('q'));

        return Quotation::query()
            ->with(['contact', 'convertedSale'])
            ->where('tenant_id', $tenant->id)
            ->when($query !== '', function (Builder $builder) use ($query): void {
                $builder->where(function (Builder $builder) use ($query): void {
                    $builder->where('number', 'like', "%{$query}%")
                        ->orWhere('status', 'like', "%{$query}%")
                        ->orWhere('metadata->reference', 'like', "%{$query}%")
                        ->orWhere('metadata->client_name', 'like', "%{$query}%")
                        ->orWhereHas('contact', fn (Builder $contactQuery) => $contactQuery->where('name', 'like', "%{$query}%"));
                });
            })
            ->when($request->query('client'), fn (Builder $builder, $client) => $builder->where('contact_id', $client))
            ->when($request->query('quote_status'), fn (Builder $builder, $status) => $builder->where('status', $status))
            ->when($request->query('from'), fn (Builder $builder, $from) => $builder->whereDate('quoted_at', '>=', $from))
            ->when($request->query('to'), fn (Builder $builder, $to) => $builder->whereDate('quoted_at', '<=', $to))
            ->latest('quoted_at');
    }

    private function customerAdvancesQuery(Tenant $tenant, Request $request): Builder
    {
        $query = trim((string) $request->query('q'));

        return CustomerAdvance::query()
            ->with('contact')
            ->where('tenant_id', $tenant->id)
            ->when($query !== '', function (Builder $builder) use ($query): void {
                $builder->where(function (Builder $builder) use ($query): void {
                    $builder->where('number', 'like', "%{$query}%")
                        ->orWhere('payment_method', 'like', "%{$query}%")
                        ->orWhere('reference', 'like', "%{$query}%")
                        ->orWhere('note', 'like', "%{$query}%")
                        ->orWhereHas('contact', function (Builder $contactQuery) use ($query): void {
                            $contactQuery->where('name', 'like', "%{$query}%")
                                ->orWhere('code', 'like', "%{$query}%")
                                ->orWhere('phone', 'like', "%{$query}%");
                        });
                });
            })
            ->when($request->query('client'), fn (Builder $builder, $client) => $builder->where('contact_id', $client))
            ->when($request->query('payment_method'), fn (Builder $builder, $method) => $builder->where('payment_method', $method))
            ->when($request->query('advance_status'), fn (Builder $builder, $status) => $builder->where('status', $status))
            ->when($request->query('from'), fn (Builder $builder, $from) => $builder->whereDate('paid_at', '>=', $from))
            ->when($request->query('to'), fn (Builder $builder, $to) => $builder->whereDate('paid_at', '<=', $to))
            ->latest('paid_at');
    }

    private function purchasesQuery(Tenant $tenant, Request $request): Builder
    {
        $query = trim((string) $request->query('q'));

        return Purchase::query()
            ->with(['supplier', 'items.item', 'returns'])
            ->where('tenant_id', $tenant->id)
            ->when($query !== '', function (Builder $builder) use ($query): void {
                $builder->where(function (Builder $builder) use ($query): void {
                    $builder->where('number', 'like', "%{$query}%")
                        ->orWhere('status', 'like', "%{$query}%")
                        ->orWhere('metadata->supplier_invoice', 'like', "%{$query}%")
                        ->orWhere('metadata->reference', 'like', "%{$query}%")
                        ->orWhereHas('supplier', fn (Builder $supplierQuery) => $supplierQuery->where('name', 'like', "%{$query}%"))
                        ->orWhereHas('items.item', fn (Builder $itemQuery) => $itemQuery->where('title', 'like', "%{$query}%"));
                });
            })
            ->when($request->query('supplier_id'), fn (Builder $builder, $supplier) => $builder->where('supplier_id', $supplier))
            ->when($request->query('purchase_status'), fn (Builder $builder, $status) => $builder->where('status', $status))
            ->when($request->query('from'), fn (Builder $builder, $from) => $builder->whereDate('ordered_at', '>=', $from))
            ->when($request->query('to'), fn (Builder $builder, $to) => $builder->whereDate('ordered_at', '<=', $to))
            ->latest('ordered_at')
            ->latest();
    }

    private function stockAdjustmentsQuery(Tenant $tenant, Request $request): Builder
    {
        $query = trim((string) $request->query('q'));

        return StockAdjustment::query()
            ->where('tenant_id', $tenant->id)
            ->when($query !== '', function (Builder $builder) use ($query): void {
                $builder->where(function (Builder $builder) use ($query): void {
                    $builder->where('number', 'like', "%{$query}%")
                        ->orWhere('warehouse', 'like', "%{$query}%")
                        ->orWhere('reason', 'like', "%{$query}%")
                        ->orWhere('note', 'like', "%{$query}%")
                        ->orWhere('lines', 'like', "%{$query}%");
                });
            })
            ->when($request->query('from'), fn (Builder $builder, $from) => $builder->whereDate('adjusted_at', '>=', $from))
            ->when($request->query('to'), fn (Builder $builder, $to) => $builder->whereDate('adjusted_at', '<=', $to))
            ->latest('adjusted_at');
    }

    private function stockTransfersQuery(Tenant $tenant, Request $request): Builder
    {
        $query = trim((string) $request->query('q'));

        return StockTransfer::query()
            ->where('tenant_id', $tenant->id)
            ->when($query !== '', function (Builder $builder) use ($query): void {
                $builder->where(function (Builder $builder) use ($query): void {
                    $builder->where('number', 'like', "%{$query}%")
                        ->orWhere('store_from', 'like', "%{$query}%")
                        ->orWhere('warehouse_from', 'like', "%{$query}%")
                        ->orWhere('store_to', 'like', "%{$query}%")
                        ->orWhere('warehouse_to', 'like', "%{$query}%")
                        ->orWhere('note', 'like', "%{$query}%")
                        ->orWhere('lines', 'like', "%{$query}%");
                });
            })
            ->when($request->query('from'), fn (Builder $builder, $from) => $builder->whereDate('transferred_at', '>=', $from))
            ->when($request->query('to'), fn (Builder $builder, $to) => $builder->whereDate('transferred_at', '<=', $to))
            ->latest('transferred_at');
    }

    private function contactsQuery(Tenant $tenant, Request $request, string $kind = 'client'): Builder
    {
        $builder = Contact::query()
            ->where('tenant_id', $tenant->id)
            ->where('kind', $kind)
            ->when($request->query('contact_status'), fn (Builder $builder, $status) => $builder->where('status', $status))
            ->when($request->query('client_type'), fn (Builder $builder, $type) => $builder->where('client_type', $type))
            ->when($request->boolean('receivable'), fn (Builder $builder) => $builder->where('outstanding_balance', '>', 0))
            ->latest();

        if ($kind === 'supplier') {
            $builder
                ->withSum(['supplierPurchases as purchases_due_sum' => fn (Builder $query) => $query->where('status', '!=', 'cancelled')], 'total_amount')
                ->withSum(['supplierPurchaseReturns as purchase_returns_due_sum' => fn (Builder $query) => $query->where('status', 'completed')], 'total_amount');
        }

        return $builder;
    }

    private function purchaseReturnsQuery(Tenant $tenant, Request $request): Builder
    {
        $query = trim((string) $request->query('q'));

        return PurchaseReturn::query()
            ->with(['purchase', 'supplier'])
            ->where('tenant_id', $tenant->id)
            ->when($query !== '', function (Builder $builder) use ($query): void {
                $builder->where(function (Builder $builder) use ($query): void {
                    $builder->where('number', 'like', "%{$query}%")
                        ->orWhere('reason', 'like', "%{$query}%")
                        ->orWhereHas('purchase', fn (Builder $purchaseQuery) => $purchaseQuery->where('number', 'like', "%{$query}%"))
                        ->orWhereHas('supplier', fn (Builder $supplierQuery) => $supplierQuery->where('name', 'like', "%{$query}%"));
                });
            })
            ->when($request->query('supplier_id'), fn (Builder $builder, $supplier) => $builder->where('supplier_id', $supplier))
            ->when($request->query('return_status'), fn (Builder $builder, $status) => $builder->where('status', $status))
            ->when($request->query('from'), fn (Builder $builder, $from) => $builder->whereDate('returned_at', '>=', $from))
            ->when($request->query('to'), fn (Builder $builder, $to) => $builder->whereDate('returned_at', '<=', $to))
            ->latest('returned_at');
    }

    private function expensesQuery(Tenant $tenant, Request $request): Builder
    {
        $query = trim((string) $request->query('q'));

        return Expense::query()
            ->where('tenant_id', $tenant->id)
            ->when($query !== '', function (Builder $builder) use ($query): void {
                $builder->where(function (Builder $builder) use ($query): void {
                    $builder->where('number', 'like', "%{$query}%")
                        ->orWhere('label', 'like', "%{$query}%")
                        ->orWhere('category', 'like', "%{$query}%")
                        ->orWhere('payment_method', 'like', "%{$query}%")
                        ->orWhere('reference', 'like', "%{$query}%")
                        ->orWhere('note', 'like', "%{$query}%");
                });
            })
            ->when($request->query('expense_category'), fn (Builder $builder, $category) => $builder->where('category', $category))
            ->when($request->query('payment_method'), fn (Builder $builder, $method) => $builder->where('payment_method', $method))
            ->when($request->query('from'), fn (Builder $builder, $from) => $builder->whereDate('spent_at', '>=', $from))
            ->when($request->query('to'), fn (Builder $builder, $to) => $builder->whereDate('spent_at', '<=', $to))
            ->latest('spent_at')
            ->latest();
    }

    private function accountTransactionsQuery(Tenant $tenant, Request $request): Builder
    {
        $query = trim((string) $request->query('q'));

        return AccountTransaction::query()
            ->with(['account', 'relatedAccount'])
            ->where('tenant_id', $tenant->id)
            ->when($query !== '', function (Builder $builder) use ($query): void {
                $builder->where(function (Builder $builder) use ($query): void {
                    $builder->where('number', 'like', "%{$query}%")
                        ->orWhere('reference', 'like', "%{$query}%")
                        ->orWhere('note', 'like', "%{$query}%")
                        ->orWhereHas('account', fn (Builder $account) => $account->where('name', 'like', "%{$query}%"));
                });
            })
            ->when($request->query('account_id'), fn (Builder $builder, $account) => $builder->where('financial_account_id', $account))
            ->when($request->query('transaction_type'), fn (Builder $builder, $type) => $builder->where('type', $type))
            ->when($request->query('from'), fn (Builder $builder, $from) => $builder->whereDate('transacted_at', '>=', $from))
            ->when($request->query('to'), fn (Builder $builder, $to) => $builder->whereDate('transacted_at', '<=', $to))
            ->latest('transacted_at')
            ->latest();
    }

    private function expenseCategoriesQuery(Tenant $tenant, Request $request): Builder
    {
        $query = trim((string) $request->query('q'));

        return ExpenseCategory::query()
            ->where('tenant_id', $tenant->id)
            ->when($query !== '', fn (Builder $builder) => $builder->where(fn (Builder $nested) => $nested->where('name', 'like', "%{$query}%")->orWhere('description', 'like', "%{$query}%")))
            ->orderBy('name');
    }

    private function validateContact(Request $request, Tenant $tenant, ?Contact $contact = null): array
    {
        return $request->validate([
            'kind' => ['required', 'in:client,supplier'],
            'code' => ['nullable', 'string', 'max:60', Rule::unique('contacts', 'code')->where('tenant_id', $tenant->id)->ignore($contact?->id)],
            'store_id' => ['nullable', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:180'],
            'client_type' => ['nullable', 'in:individual,school,company,wholesale,teacher,student'],
            'status' => ['required', 'in:active,archived'],
            'phone' => ['nullable', 'string', 'max:80'],
            'secondary_phone' => ['nullable', 'string', 'max:80'],
            'email' => ['nullable', 'email', 'max:160'],
            'cin' => ['nullable', 'string', 'max:80'],
            'ice' => ['nullable', 'string', 'max:80'],
            'tax_number' => ['nullable', 'string', 'max:120'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'opening_balance' => ['nullable', 'numeric'],
            'advance_balance' => ['nullable', 'numeric', 'min:0'],
            'outstanding_balance' => ['nullable', 'numeric', 'min:0'],
            'fine_balance' => ['nullable', 'numeric', 'min:0'],
            'membership_expires_at' => ['nullable', 'date'],
            'address' => ['nullable', 'string', 'max:700'],
            'country' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:120'],
            'postcode' => ['nullable', 'string', 'max:40'],
            'location_link' => ['nullable', 'url', 'max:500'],
            'shipping_country' => ['nullable', 'string', 'max:120'],
            'shipping_state' => ['nullable', 'string', 'max:120'],
            'shipping_city' => ['nullable', 'string', 'max:120'],
            'shipping_postcode' => ['nullable', 'string', 'max:40'],
            'shipping_address' => ['nullable', 'string', 'max:700'],
            'shipping_location_link' => ['nullable', 'url', 'max:500'],
            'price_level_type' => ['required', 'in:increase,decrease'],
            'price_level' => ['nullable', 'numeric', 'min:0'],
            'tags' => ['nullable', 'string', 'max:300'],
            'note' => ['nullable', 'string', 'max:700'],
            'attachment' => ['nullable', 'file', 'max:4096'],
            'copy_address' => ['nullable', 'boolean'],
        ]);
    }

    private function contactPayload(Request $request, Tenant $tenant, array $data, ?Contact $contact = null): array
    {
        $copyAddress = $request->boolean('copy_address');
        $tags = collect(explode(',', (string) ($data['tags'] ?? '')))
            ->map(fn (string $tag) => trim($tag))
            ->filter()
            ->values()
            ->all();

        $attachmentPath = $contact?->attachment_path;
        if ($request->hasFile('attachment')) {
            $attachmentPath = $request->file('attachment')->store('contacts', 'public');
        }

        $payload = [
            'tenant_id' => $tenant->id,
            'kind' => $data['kind'],
            'code' => filled($data['code'] ?? null) ? $data['code'] : ($contact?->code ?? $this->nextContactCode($tenant, $data['kind'])),
            'store_id' => $data['store_id'] ?? null,
            'name' => $data['name'],
            'client_type' => $data['client_type'] ?? ($data['kind'] === 'supplier' ? 'company' : 'individual'),
            'status' => $data['status'],
            'phone' => $data['phone'] ?? $data['secondary_phone'] ?? null,
            'email' => $data['email'] ?? null,
            'cin' => $data['cin'] ?? null,
            'ice' => $data['ice'] ?? null,
            'credit_limit' => round((float) ($data['credit_limit'] ?? 0), 2),
            'opening_balance' => round((float) ($data['opening_balance'] ?? 0), 2),
            'tax_number' => $data['tax_number'] ?? null,
            'address' => $data['address'] ?? null,
            'country' => $data['country'] ?? null,
            'state' => $data['state'] ?? null,
            'city' => $data['city'] ?? null,
            'postcode' => $data['postcode'] ?? null,
            'location_link' => $data['location_link'] ?? null,
            'shipping_country' => $copyAddress ? ($data['country'] ?? null) : ($data['shipping_country'] ?? null),
            'shipping_state' => $copyAddress ? ($data['state'] ?? null) : ($data['shipping_state'] ?? null),
            'shipping_city' => $copyAddress ? ($data['city'] ?? null) : ($data['shipping_city'] ?? null),
            'shipping_postcode' => $copyAddress ? ($data['postcode'] ?? null) : ($data['shipping_postcode'] ?? null),
            'shipping_address' => $copyAddress ? ($data['address'] ?? null) : ($data['shipping_address'] ?? null),
            'shipping_location_link' => $copyAddress ? ($data['location_link'] ?? null) : ($data['shipping_location_link'] ?? null),
            'price_level_type' => $data['price_level_type'],
            'price_level' => round((float) ($data['price_level'] ?? 0), 2),
            'attachment_path' => $attachmentPath,
            'tags' => $tags,
            'advance_balance' => round((float) ($data['advance_balance'] ?? $contact?->advance_balance ?? 0), 2),
            'outstanding_balance' => round((float) ($data['outstanding_balance'] ?? $contact?->outstanding_balance ?? 0), 2),
            'fine_balance' => round((float) ($data['fine_balance'] ?? $contact?->fine_balance ?? 0), 2),
            'membership_expires_at' => $data['membership_expires_at'] ?? null,
        ];

        return $payload;
    }

    private function salePaidAmount(Sale $sale): float
    {
        $paid = data_get($sale->metadata, 'paid_amount');
        if ($paid !== null) {
            return min((float) $paid, (float) $sale->total_amount);
        }

        return $sale->status === 'paid' ? (float) $sale->total_amount : 0.0;
    }

    private function invoiceNumber(string $saleNumber): string
    {
        return 'FAC-'.$saleNumber;
    }

    private function tenant(): Tenant
    {
        return Tenant::query()->firstOrFail();
    }

    private function validateUserAccess(Request $request, Tenant $tenant, ?\App\Models\User $user = null): array
    {
        $roleKeys = Role::where('tenant_id', $tenant->id)->pluck('key')->all();
        $permissionKeys = array_keys($this->permissionCatalog());

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user?->id)],
            'phone' => ['nullable', 'string', 'max:60'],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:8', 'max:120'],
            'avatar_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'role' => ['required', Rule::in($roleKeys)],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::in($permissionKeys)],
            'store_access' => ['nullable', 'array'],
            'store_access.*' => ['string', 'max:120'],
        ]);

        $data['permissions'] ??= [];
        $data['store_access'] ??= [];

        return $data;
    }

    private function tenantUserPayload(array $data): array
    {
        return [
            'role' => $data['role'],
            'permissions' => json_encode(array_values($data['permissions'] ?? []), JSON_UNESCAPED_UNICODE),
            'store_access' => json_encode(array_values(array_filter($data['store_access'] ?? [])), JSON_UNESCAPED_UNICODE),
        ];
    }

    private function validateRole(Request $request, Tenant $tenant, ?Role $role = null): array
    {
        $permissionKeys = array_keys($this->permissionCatalog());

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'key' => ['required', 'alpha_dash', 'max:80', Rule::unique('roles', 'key')->where('tenant_id', $tenant->id)->ignore($role?->id)],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::in($permissionKeys)],
        ]);

        $data['permissions'] ??= [];

        return $data;
    }

    private function permissionCatalog(): array
    {
        return [
            'dashboard.view' => 'Tableau de bord',
            'items.view' => 'Catalogue: voir',
            'items.create' => 'Catalogue: créer',
            'items.edit' => 'Catalogue: modifier',
            'items.delete' => 'Catalogue: supprimer',
            'items.import' => 'Catalogue: importer',
            'stock.adjust' => 'Stock: ajuster',
            'stock.transfer' => 'Stock: transférer',
            'sales.view' => 'Ventes: voir',
            'sales.create' => 'Ventes: créer',
            'sales.refund' => 'Ventes: rembourser',
            'sales.payments' => 'Ventes: paiements',
            'purchases.view' => 'Achats: voir',
            'purchases.create' => 'Achats: créer',
            'purchases.receive' => 'Achats: réceptionner',
            'contacts.view' => 'Contacts: voir',
            'contacts.create' => 'Contacts: créer',
            'contacts.edit' => 'Contacts: modifier',
            'finance.view' => 'Finances: voir',
            'finance.manage' => 'Finances: gérer',
            'reports.view' => 'Rapports',
            'settings.users' => 'Paramètres: utilisateurs',
            'settings.roles' => 'Paramètres: rôles',
            'settings.theme' => 'Paramètres: thème',
            'settings.audit' => 'Paramètres: audit',
        ];
    }

    private function validateSettingsReference(Request $request, string $bucket): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['nullable', 'alpha_dash', 'max:80'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['key'] = $data['code'] ?: Str::slug($data['name']);
        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }

    private function settingsRecords(Tenant $tenant, string $bucket): array
    {
        $defaults = [
            'payment_types' => [
                ['key' => 'cash', 'name' => 'Espèces', 'code' => 'cash', 'description' => 'Paiement comptoir en espèces', 'is_active' => true],
                ['key' => 'card', 'name' => 'Carte', 'code' => 'card', 'description' => 'TPE / carte bancaire', 'is_active' => true],
                ['key' => 'transfer', 'name' => 'Virement', 'code' => 'transfer', 'description' => 'Paiement par virement', 'is_active' => true],
                ['key' => 'cheque', 'name' => 'Chèque', 'code' => 'cheque', 'description' => 'Paiement par chèque', 'is_active' => true],
                ['key' => 'advance', 'name' => 'Avance client', 'code' => 'advance', 'description' => 'Déduction sur avance client', 'is_active' => true],
            ],
            'countries' => [
                ['key' => 'maroc', 'name' => 'Maroc', 'code' => 'MA', 'description' => 'Pays par défaut', 'is_active' => true],
            ],
            'states' => [
                ['key' => 'casablanca-settat', 'name' => 'Casablanca-Settat', 'code' => 'CAS', 'country' => 'Maroc', 'description' => null, 'is_active' => true],
                ['key' => 'rabat-sale-kenitra', 'name' => 'Rabat-Salé-Kénitra', 'code' => 'RSK', 'country' => 'Maroc', 'description' => null, 'is_active' => true],
            ],
            'tax_groups' => [],
        ];

        return collect(data_get($tenant->settings, $bucket, $defaults[$bucket] ?? []))
            ->map(function ($record) use ($bucket): array {
                $record = is_array($record) ? $record : ['name' => (string) $record];
                $name = trim((string) ($record['name'] ?? ''));
                $key = (string) ($record['key'] ?? $record['code'] ?? Str::slug($name));

                return [
                    'key' => $key !== '' ? $key : Str::random(8),
                    'name' => $name,
                    'code' => (string) ($record['code'] ?? $key),
                    'description' => $record['description'] ?? null,
                    'country' => $record['country'] ?? null,
                    'rate' => (float) ($record['rate'] ?? 0),
                    'secondary_taxes' => $record['secondary_taxes'] ?? null,
                    'is_active' => (bool) ($record['is_active'] ?? true),
                ];
            })
            ->filter(fn (array $record) => $record['name'] !== '')
            ->unique('key')
            ->values()
            ->all();
    }

    private function upsertSettingsRecord(Tenant $tenant, string $bucket, array $data, ?string $existingKey = null): void
    {
        $records = collect($this->settingsRecords($tenant, $bucket));
        $key = $existingKey ?: ($data['key'] ?? Str::slug($data['name']));
        $payload = array_merge($data, [
            'key' => $key,
            'code' => $data['code'] ?? $key,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        if ($existingKey) {
            abort_unless($records->contains(fn (array $record) => $record['key'] === $existingKey), 404);
            $records = $records->map(fn (array $record) => $record['key'] === $existingKey ? array_merge($record, $payload) : $record);
        } else {
            abort_if($records->contains(fn (array $record) => $record['key'] === $key), 422, 'Une entrée avec cette clé existe déjà.');
            $records->push($payload);
        }

        $settings = $tenant->settings ?? [];
        $settings[$bucket] = $records->values()->all();
        $tenant->update(['settings' => $settings]);
    }

    private function deleteSettingsRecord(Tenant $tenant, string $bucket, string $key): void
    {
        $records = collect($this->settingsRecords($tenant, $bucket));
        abort_unless($records->contains(fn (array $record) => $record['key'] === $key), 404);

        $settings = $tenant->settings ?? [];
        $settings[$bucket] = $records->reject(fn (array $record) => $record['key'] === $key)->values()->all();
        $tenant->update(['settings' => $settings]);
    }

    private function storeAccessOptions(Tenant $tenant): array
    {
        $settingsStores = collect($this->storeCatalog($tenant))->pluck('name');

        return $settingsStores
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function storeCatalog(Tenant $tenant): array
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
                    'manager' => $store['manager'] ?? null,
                    'is_active' => (bool) ($store['is_active'] ?? true),
                ];
            })
            ->filter(fn (array $store) => $store['name'] !== '')
            ->values();

        if ($stores->isEmpty()) {
            $stores = collect([
                ['key' => 'magasin-principal', 'name' => 'Magasin principal', 'type' => 'store', 'address' => $tenant->address, 'phone' => $tenant->phone, 'manager' => null, 'is_active' => true],
                ['key' => 'depot', 'name' => 'Dépôt', 'type' => 'warehouse', 'address' => null, 'phone' => null, 'manager' => null, 'is_active' => true],
                ['key' => 'rayon-scolaire', 'name' => 'Rayon scolaire', 'type' => 'area', 'address' => null, 'phone' => null, 'manager' => null, 'is_active' => true],
            ]);
        }

        return $stores->unique('key')->values()->all();
    }

    private function currentStore(Tenant $tenant): array
    {
        $stores = collect($this->storeCatalog($tenant));
        $currentKey = data_get($tenant->settings, 'current_store');

        return $stores->firstWhere('key', $currentKey) ?? $stores->first();
    }

    private function validateStore(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'in:store,warehouse,area,branch'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:60'],
            'manager' => ['nullable', 'string', 'max:120'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    private function storePayload(array $data, string $key): array
    {
        return [
            'key' => $key,
            'name' => $data['name'],
            'type' => $data['type'],
            'address' => $data['address'] ?? null,
            'phone' => $data['phone'] ?? null,
            'manager' => $data['manager'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? false),
        ];
    }

    private function uniqueStoreKey(array $stores, string $name): string
    {
        $base = Str::slug($name) ?: 'magasin';
        $keys = collect($stores)->pluck('key');
        $key = $base;
        $i = 2;

        while ($keys->contains($key)) {
            $key = $base.'-'.$i;
            $i++;
        }

        return $key;
    }

    private function themePresets(): array
    {
        return [
            'default' => [
                'primary' => '#3157D5',
                'accent' => '#0F9F8A',
                'success' => '#16A34A',
                'warning' => '#D97706',
                'danger' => '#E11D48',
                'info' => '#0284C7',
                'background' => '#F4F7FB',
                'surface_color' => '#FFFFFF',
                'surface_muted' => '#EEF3F8',
                'text' => '#101828',
                'muted' => '#64748B',
                'border' => '#D7DEE9',
                'font_scale' => '1',
                'density' => 'comfortable',
                'radius' => '12',
            ],
            'classic' => [
                'primary' => '#4F46E5',
                'accent' => '#0EA5E9',
                'success' => '#059669',
                'warning' => '#D97706',
                'danger' => '#DC2626',
                'info' => '#0284C7',
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
                'warning' => '#B45309',
                'danger' => '#BE123C',
                'info' => '#0369A1',
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
     * @return array{title: string, filename: string, headers: array<int, string>, rows: array<int, array<int, string|int|float>>}
     */
    private function importExampleRows(string $kind): array
    {
        return match ($kind) {
            'categories' => [
                'title' => 'Liste des catégories',
                'filename' => 'exemple-import-categories.xlsx',
                'headers' => ['Nom de catégorie', 'La description', 'Statut'],
                'rows' => [
                    ['FOURNITURE SCOLAIRE', 'Cahiers, cartables, trousses et rentrée scolaire', 'Active'],
                    ['ROMANS', 'Livres de lecture', 'Active'],
                ],
            ],
            'brands' => [
                'title' => 'Liste des marques',
                'filename' => 'exemple-import-marques.xlsx',
                'headers' => ['Marque', 'La description', 'Statut'],
                'rows' => [
                    ['OXFORD', 'Papeterie et cahiers', 'Active'],
                    ['BORDAS', 'Éditeur scolaire', 'Active'],
                ],
            ],
            'variants' => [
                'title' => 'Liste des variantes',
                'filename' => 'exemple-import-variantes.xlsx',
                'headers' => ['Nom de la variante', 'La description', 'Statut'],
                'rows' => [
                    ['BLEU MARINE', 'Couleur', 'Active'],
                    ['RELIÉ', 'Format livre', 'Active'],
                ],
            ],
            'services' => [
                'title' => 'Liste des services',
                'filename' => 'exemple-import-services.xlsx',
                'headers' => ['Code de barre', "Nom de l'article", "Catégorie/Type d'élément", 'Unité', 'Prix de vente', 'Impôt', 'Statut'],
                'rows' => [
                    ['', 'Photocopie A4 noir et blanc', 'Services[SERVICE]', 'Service', '0.50', 'Sans TVA(0.00%)', 'Active'],
                    ['', 'Adhésion annuelle', 'Services[SERVICE]', 'Service', '100.00', 'Sans TVA(0.00%)', 'Active'],
                ],
            ],
            default => [
                'title' => "Liste d'articles",
                'filename' => 'exemple-import-articles.xlsx',
                'headers' => ['Code de barre', "Nom de l'article", "Catégorie/Type d'élément", 'Unité', 'Stock', "Quantité d'alerte", 'Prix de vente', 'Impôt', 'Statut', 'Action'],
                'rows' => [
                    ['9780000000001', 'Cahier 96 pages grand format', 'FOURNITURE SCOLAIRE[ITEM]', 'Pièce', '50', '5', '12.00', 'Sans TVA(0.00%)', 'Active', ''],
                    ['9780000000002', 'Roman exemple relié', 'ROMANS[ITEM]', 'Pièce', '8', '2', '85.00', 'TVA 7%(7.00%)', 'Active', ''],
                ],
            ],
        };
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string|int|float>>  $rows
     */
    private function buildSimpleXlsx(string $title, array $headers, array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'librairepro-import-').'.xlsx';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Import" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->simpleWorksheetXml($title, $headers, $rows));
        $zip->close();

        return $path;
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string|int|float>>  $rows
     */
    private function simpleWorksheetXml(string $title, array $headers, array $rows): string
    {
        $sheetRows = [[$title], $headers, ...$rows];
        $xmlRows = [];

        foreach ($sheetRows as $rowIndex => $row) {
            $cells = [];
            foreach (array_values($row) as $columnIndex => $value) {
                $reference = $this->columnLetters($columnIndex + 1).($rowIndex + 1);
                $cells[] = '<c r="'.$reference.'" t="inlineStr"><is><t>'.$this->xmlEscape((string) $value).'</t></is></c>';
            }
            $xmlRows[] = '<row r="'.($rowIndex + 1).'">'.implode('', $cells).'</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.implode('', $xmlRows).'</sheetData></worksheet>';
    }

    private function columnLetters(int $index): string
    {
        $letters = '';
        while ($index > 0) {
            $index--;
            $letters = chr(65 + ($index % 26)).$letters;
            $index = intdiv($index, 26);
        }

        return $letters;
    }

    private function xmlEscape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
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

    private function paymentMethodLabel(?string $method): string
    {
        return [
            'cash' => 'Espèces',
            'card' => 'Carte',
            'transfer' => 'Virement',
            'cheque' => 'Chèque',
            'advance' => 'Avance',
            'other' => 'Autre',
        ][$method] ?? ucfirst((string) $method);
    }
}
