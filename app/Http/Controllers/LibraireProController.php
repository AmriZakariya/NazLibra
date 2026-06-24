<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\CashRegisterMovement;
use App\Models\CashRegisterSession;
use App\Models\Contact;
use App\Models\Coupon;
use App\Models\CustomerAdvance;
use App\Models\DeliveryOrder;
use App\Models\DiscountRule;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\AccountTransaction;
use App\Models\FinancialAccount;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\ItemLocationStock;
use App\Models\ItemVariant;
use App\Models\Loan;
use App\Models\Location;
use App\Models\OnlineOrder;
use App\Models\OnlineOrderItem;
use App\Models\PosTicket;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchasePayment;
use App\Models\PurchaseReturn;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleInvoice;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\SaleReturn;
use App\Models\StockAdjustment;
use App\Models\Stocktake;
use App\Models\StocktakeItem;
use App\Models\StockTransfer;
use App\Models\Tax;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\VariantOption;
use App\Models\VirtualDeviceSession;
use App\Rules\FourDigitPin;
use App\Services\CashRegisterService;
use App\Services\Documents\DocumentNumberGenerator;
use App\Services\Documents\InvoiceService;
use App\Support\AppModules;
use App\Support\BusinessMode;
use App\Support\Locale;
use App\Support\TenantContext;
use App\Support\TenantClock;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use Yajra\DataTables\Facades\DataTables;
use ZipArchive;

class LibraireProController extends Controller
{
    public function __construct(private readonly DocumentNumberGenerator $numbers) {}

    private function noStoreJson(array $payload): JsonResponse
    {
        return response()
            ->json($payload)
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }

    private function actorMetadata(string $prefix): array
    {
        $user = auth()->user();

        return [
            $prefix.'_by' => $user?->id,
            $prefix.'_by_name' => $user?->name,
            $prefix.'_by_at' => now()->toIso8601String(),
        ];
    }

    private function creationActorMetadata(): array
    {
        $metadata = $this->actorMetadata('created');

        return array_merge($metadata, [
            'updated_by' => $metadata['created_by'],
            'updated_by_name' => $metadata['created_by_name'],
            'updated_by_at' => $metadata['created_by_at'],
        ]);
    }

    public function switchLocale(Request $request, string $locale): RedirectResponse
    {
        abort_unless(in_array($locale, Locale::SUPPORTED, true), 404);

        $tenant = $this->tenant();
        $settings = $tenant->settings ?? [];
        $settings['language_id'] = $locale;
        $settings['locale'] = $locale === 'ar' ? 'ar_MA' : 'fr_MA';

        $tenant->forceFill([
            'locale' => $settings['locale'],
            'settings' => $settings,
        ])->save();

        session(['librairepro_locale' => $locale]);
        Locale::apply($tenant->fresh());

        return redirect()->back()->with('status', $locale === 'ar' ? 'تم تغيير اللغة إلى العربية.' : 'Langue changée en français.');
    }

    public function dashboard(Request $request): View
    {
        $tenant = $this->tenant();
        $preset = (string) $request->query('period', 'today');
        $allowedPresets = ['today', 'yesterday', 'week', 'month', 'year', 'custom'];
        $preset = in_array($preset, $allowedPresets, true) ? $preset : 'today';

        [$from, $to] = match ($preset) {
            'yesterday' => [now()->subDay()->startOfDay(), now()->subDay()->endOfDay()],
            'week' => [now()->subDays(6)->startOfDay(), now()->endOfDay()],
            'month' => [now()->startOfMonth(), now()->endOfDay()],
            'year' => [now()->startOfYear(), now()->endOfDay()],
            'custom' => [
                $request->filled('from') ? Carbon::parse((string) $request->query('from'))->startOfDay() : now()->startOfDay(),
                $request->filled('to') ? Carbon::parse((string) $request->query('to'))->endOfDay() : now()->endOfDay(),
            ],
            default => [now()->startOfDay(), now()->endOfDay()],
        };

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        $periodDays = max(1, $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1);
        $previousFrom = $from->copy()->subDays($periodDays);
        $previousTo = $from->copy()->subSecond();
        $trendLimit = min(31, $periodDays);
        $trendStart = $to->copy()->startOfDay()->subDays($trendLimit - 1);
        $today = Carbon::today();

        $salesQuery = $tenant->sales()
            ->whereBetween('sold_at', [$from, $to]);
        $previousSalesQuery = $tenant->sales()
            ->whereBetween('sold_at', [$previousFrom, $previousTo]);
        $paymentsQuery = SalePayment::query()
            ->where('tenant_id', $tenant->id)
            ->whereBetween('paid_at', [$from, $to]);
        $purchasesQuery = Purchase::query()
            ->where('tenant_id', $tenant->id)
            ->where(function (Builder $builder) use ($from, $to): void {
                $builder->whereBetween('ordered_at', [$from->toDateString(), $to->toDateString()])
                    ->orWhereBetween('received_at', [$from->toDateString(), $to->toDateString()]);
            });
        $expensesQuery = Expense::query()
            ->where('tenant_id', $tenant->id)
            ->whereBetween('spent_at', [$from->toDateString(), $to->toDateString()]);
        $saleReturnsQuery = SaleReturn::query()
            ->where('tenant_id', $tenant->id)
            ->whereBetween('returned_at', [$from, $to]);
        $purchaseReturnsQuery = PurchaseReturn::query()
            ->where('tenant_id', $tenant->id)
            ->whereBetween('returned_at', [$from, $to]);
        $quotationsQuery = Quotation::query()
            ->where('tenant_id', $tenant->id)
            ->whereBetween('quoted_at', [$from, $to]);

        $periodRevenue = (float) (clone $salesQuery)->sum('total_amount');
        $previousRevenue = (float) (clone $previousSalesQuery)->sum('total_amount');
        $periodPayments = (float) (clone $paymentsQuery)->sum('amount');
        $periodPurchases = (float) (clone $purchasesQuery)->sum('total_amount');
        $periodExpenses = (float) (clone $expensesQuery)->sum('amount');
        $periodSaleReturns = (float) (clone $saleReturnsQuery)->sum('total_amount');
        $periodPurchaseReturns = (float) (clone $purchaseReturnsQuery)->sum('total_amount');
        $dailyItems = DB::table('sale_items')
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->where('sales.tenant_id', $tenant->id)
            ->whereBetween('sales.sold_at', [$from, $to])
            ->sum('sale_items.quantity');
        $purchaseCost = (float) DB::table('sale_items')
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->where('sales.tenant_id', $tenant->id)
            ->whereBetween('sales.sold_at', [$from, $to])
            ->sum('sale_items.total_cost');
        $netRevenue = max(0, $periodRevenue - $periodSaleReturns);
        $grossProfit = $netRevenue - $purchaseCost;
        $netProfit = $grossProfit - $periodExpenses;
        $revenueDelta = $previousRevenue > 0
            ? (($periodRevenue - $previousRevenue) / max(1, $previousRevenue)) * 100
            : ($periodRevenue > 0 ? 100 : 0);

        $trendRows = $tenant->sales()
            ->selectRaw('date(sold_at) as day, sum(total_amount) as total')
            ->whereBetween('sold_at', [$trendStart, $to])
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        $salesTrend = collect(range(0, $trendLimit - 1))->map(function (int $offset) use ($trendStart, $trendRows) {
            $day = $trendStart->copy()->addDays($offset)->toDateString();

            return (object) [
                'day' => $day,
                'total' => (float) ($trendRows[$day]->total ?? 0),
            ];
        });

        $categoryBreakdown = DB::table('sale_items')
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->leftJoin('items', 'sale_items.item_id', '=', 'items.id')
            ->leftJoin('categories', 'items.category_id', '=', 'categories.id')
            ->where('sales.tenant_id', $tenant->id)
            ->whereBetween('sales.sold_at', [$from, $to])
            ->selectRaw("coalesce(categories.name, 'Sans catégorie') as category_name, sum(sale_items.quantity) as quantity, sum(sale_items.total_price) as revenue")
            ->groupByRaw("coalesce(categories.name, 'Sans catégorie')")
            ->orderByDesc('revenue')
            ->limit(6)
            ->get();
        $expenseBreakdown = (clone $expensesQuery)
            ->selectRaw('category, sum(amount) as total')
            ->groupBy('category')
            ->orderByDesc('total')
            ->limit(5)
            ->get();
        $hourlyHeatmap = DB::table('sales')
            ->where('tenant_id', $tenant->id)
            ->whereBetween('sold_at', [$from, $to])
            ->selectRaw("cast(strftime('%H', sold_at) as integer) as hour, count(*) as tickets, sum(total_amount) as total")
            ->groupBy('hour')
            ->orderBy('hour')
            ->get()
            ->keyBy('hour');
        $hourlyHeatmap = collect(range(8, 22))->map(fn (int $hour) => (object) [
            'hour' => $hour,
            'tickets' => (int) ($hourlyHeatmap[$hour]->tickets ?? 0),
            'total' => (float) ($hourlyHeatmap[$hour]->total ?? 0),
        ]);
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
            ->whereBetween('paid_at', [$from, $to])
            ->selectRaw('method, sum(amount) as total')
            ->groupBy('method')
            ->orderByDesc('total')
            ->get();
        $newClients = Contact::query()
            ->where('tenant_id', $tenant->id)
            ->where('kind', 'client')
            ->whereBetween('created_at', [$from, $to])
            ->count();
        $activeClients = (clone $salesQuery)->whereNotNull('contact_id')->distinct('contact_id')->count('contact_id');
        $ticketCount = (clone $salesQuery)->count();
        $averageTicket = $ticketCount > 0 ? $periodRevenue / $ticketCount : 0;
        $openReceivables = (float) $tenant->sales()->whereIn('status', ['partial', 'unpaid'])->sum('total_amount');
        $cashDrawerIn = (float) (clone $salesQuery)->get()->sum(fn (Sale $sale) => (float) data_get($sale->metadata, 'cash_register.cash_drawer_in', 0));
        $cashReceived = (float) (clone $salesQuery)->get()->sum(fn (Sale $sale) => (float) data_get($sale->metadata, 'cash_register.cash_received', 0));
        $cashChange = (float) (clone $salesQuery)->get()->sum(fn (Sale $sale) => (float) data_get($sale->metadata, 'cash_register.cash_change', 0));
        $periodLabels = [
            'today' => 'Aujourd’hui',
            'yesterday' => 'Hier',
            'week' => '7 derniers jours',
            'month' => 'Mois courant',
            'year' => 'Année courante',
            'custom' => 'Période personnalisée',
        ];

        return view('librairepro.dashboard', [
            'tenant' => $tenant,
            'active' => 'dashboard',
            'filters' => [
                'period' => $preset,
                'label' => $periodLabels[$preset],
                'from' => $from,
                'to' => $to,
                'previous_from' => $previousFrom,
                'previous_to' => $previousTo,
                'days' => $periodDays,
            ],
            'stats' => [
                ['label' => 'Chiffre d’affaires', 'value' => $this->money($periodRevenue), 'tone' => $revenueDelta >= 0 ? 'success' : 'danger', 'delta_value' => ($revenueDelta >= 0 ? '+' : '').number_format($revenueDelta, 0, ',', ' ').'%', 'delta_label' => 'vs période précédente'],
                ['label' => 'Articles vendus', 'value' => number_format((float) $dailyItems, 0, ',', ' '), 'tone' => 'info', 'delta' => $periodLabels[$preset]],
                ['label' => 'Ticket moyen', 'value' => $this->money($averageTicket), 'tone' => 'primary', 'delta_value' => number_format($ticketCount, 0, ',', ' '), 'delta_label' => 'ticket(s)'],
                ['label' => 'Résultat net estimé', 'value' => $this->money($netProfit), 'tone' => $netProfit >= 0 ? 'success' : 'danger', 'delta' => 'Ventes - coûts - dépenses'],
            ],
            'reportCards' => [
                ['label' => 'Ventes brutes', 'value' => $this->money($periodRevenue), 'href' => route('module', ['module' => 'sales', 'section' => 'list', 'from' => $from->toDateString(), 'to' => $to->toDateString()]), 'tone' => 'primary', 'hint' => 'CA encaissé ou facturé'],
                ['label' => 'Retours vente', 'value' => $this->money($periodSaleReturns), 'href' => route('module', ['module' => 'sales', 'section' => 'returns', 'from' => $from->toDateString(), 'to' => $to->toDateString()]), 'tone' => 'danger', 'hint' => 'Avoirs et remboursements'],
                ['label' => 'Paiements reçus', 'value' => $this->money($periodPayments), 'href' => route('module', ['module' => 'sales', 'section' => 'payments', 'from' => $from->toDateString(), 'to' => $to->toDateString()]), 'tone' => 'success', 'hint' => 'Espèces, carte, virement'],
                ['label' => 'Achats', 'value' => $this->money($periodPurchases), 'href' => route('module', ['module' => 'purchases', 'section' => 'list']), 'tone' => 'warning', 'hint' => 'Commandes fournisseur'],
                ['label' => 'Retours achat', 'value' => $this->money($periodPurchaseReturns), 'href' => route('module', ['module' => 'purchases', 'section' => 'returns']), 'tone' => 'warning', 'hint' => 'Retours fournisseurs'],
                ['label' => 'Dépenses', 'value' => $this->money($periodExpenses), 'href' => route('module', ['module' => 'finance', 'section' => 'expenses']), 'tone' => 'danger', 'hint' => 'Charges de la période'],
                ['label' => 'Marge brute estimée', 'value' => $this->money($grossProfit), 'href' => route('module', ['module' => 'reports', 'section' => 'profit-loss', 'from' => $from->toDateString(), 'to' => $to->toDateString()]), 'tone' => $grossProfit >= 0 ? 'success' : 'danger', 'hint' => 'Net ventes - coût articles'],
                ['label' => 'Créances ouvertes', 'value' => $this->money($openReceivables), 'href' => route('module', ['module' => 'reports', 'section' => 'sales-payments']), 'tone' => 'info', 'hint' => 'Ventes partielles / impayées'],
            ],
            'operations' => [
                'pending_tickets' => PosTicket::where('tenant_id', $tenant->id)->where('status', 'held')->count(),
                'pending_deliveries' => DeliveryOrder::where('tenant_id', $tenant->id)->whereIn('status', ['pending', 'preparing', 'dispatched'])->count(),
                'open_quotes' => Quotation::where('tenant_id', $tenant->id)->whereIn('status', ['draft', 'sent'])->count(),
                'draft_purchases' => Purchase::where('tenant_id', $tenant->id)->whereIn('status', ['draft', 'ordered', 'partially_received'])->count(),
                'open_cash_register' => CashRegisterSession::where('tenant_id', $tenant->id)->where('status', 'open')->exists(),
            ],
            'stockSummary' => [
                'health' => $stockHealth,
                'low' => $lowStockCount,
                'out' => $outOfStockCount,
                'value' => $stockValue,
            ],
            'cashSummary' => [
                'drawer_in' => $cashDrawerIn,
                'received' => $cashReceived,
                'change' => $cashChange,
            ],
            'clientSummary' => [
                'new' => $newClients,
                'active' => $activeClients,
                'total' => Contact::where('tenant_id', $tenant->id)->where('kind', 'client')->count(),
            ],
            'lowStockItems' => (bool) data_get($tenant->settings, 'pos.low_stock_dashboard', true)
                ? $tenant->items()->with('category')->where('type', '!=', 'service')->whereColumn('stock_quantity', '<=', 'min_stock_threshold')->orderBy('stock_quantity')->take(6)->get()
                : collect(),
            'recentSales' => (clone $salesQuery)->with('contact')->withCount('items')->latest('sold_at')->take(8)->get(),
            'activeLoans' => $tenant->loans()->with(['member', 'item'])->whereIn('status', ['borrowed', 'overdue'])->latest()->take(5)->get(),
            'salesTrend' => $salesTrend,
            'topItems' => DB::table('sale_items')
                ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
                ->where('sales.tenant_id', $tenant->id)
                ->whereBetween('sales.sold_at', [$from, $to])
                ->selectRaw('sale_items.name, sum(sale_items.quantity) as quantity, sum(sale_items.total_price) as revenue')
                ->groupBy('sale_items.name')
                ->orderByDesc('quantity')
                ->limit(8)
                ->get(),
            'categoryBreakdown' => $categoryBreakdown,
            'expenseBreakdown' => $expenseBreakdown,
            'hourlyHeatmap' => $hourlyHeatmap,
            'paymentBreakdown' => $paymentBreakdown,
            'recentActivity' => collect([
                ['label' => 'Ventes encaissées', 'value' => $tenant->sales()->whereDate('sold_at', $today)->count(), 'href' => route('module', ['module' => 'sales', 'section' => 'list'])],
                ['label' => 'Paiements reçus', 'value' => SalePayment::where('tenant_id', $tenant->id)->whereDate('paid_at', $today)->count(), 'href' => route('module', ['module' => 'sales', 'section' => 'payments'])],
                ['label' => 'Retours vente', 'value' => SaleReturn::where('tenant_id', $tenant->id)->whereDate('returned_at', $today)->count(), 'href' => route('module', ['module' => 'sales', 'section' => 'returns'])],
                ['label' => 'Avances clients', 'value' => CustomerAdvance::where('tenant_id', $tenant->id)->whereDate('paid_at', $today)->count(), 'href' => route('module', ['module' => 'finance', 'section' => 'advances'])],
            ]),
        ]);
    }

    public function functionalityGuide(): View
    {
        $tenant = $this->tenant();
        abort_unless(AppModules::enabled($tenant, 'guide'), 404);
        $groups = $this->functionalityGuideGroups();
        $features = collect($groups)->flatMap(fn (array $group) => $group['features']);
        $visibleCount = $features->where('status', 'visible')->count();
        $codeOnlyCount = $features->where('status', 'code')->count();
        $reviewCount = $features->where('status', 'review')->count();

        return view('librairepro.functionality-guide', [
            'tenant' => $tenant,
            'active' => 'guide',
            'groups' => $groups,
            'summary' => [
                'groups' => count($groups),
                'features' => $features->count(),
                'visible' => $visibleCount,
                'code' => $codeOnlyCount,
                'review' => $reviewCount,
            ],
        ]);
    }

    private function functionalityGuideGroups(): array
    {
        $feature = function (string $name, string $description, string $href, string $status = 'visible'): array {
            return compact('name', 'description', 'href', 'status');
        };

        return [
            [
                'title' => 'Pilotage',
                'description' => 'Vue globale, raccourcis et indicateurs opérationnels.',
                'features' => [
                    $feature('Tableau de bord', 'KPIs de vente, stock, caisse, paiements, clients actifs, tendances et période filtrable.', route('dashboard')),
                    $feature('Centre d action', 'Tickets en attente, livraisons, devis ouverts, achats à suivre et état du tiroir caisse.', route('dashboard')),
                    $feature('Raccourcis rapides', 'Accès direct aux étiquettes, import, création client et achat depuis le dashboard.', route('dashboard')),
                    $feature('Guide des fonctionnalités', 'Inventaire scannable des modules, liens et éléments à revoir.', route('functionality-guide')),
                ],
            ],
            [
                'title' => 'Catalogue',
                'description' => 'Articles, services, référentiels, imports et impression.',
                'features' => [
                    $feature('Liste des articles', 'Recherche, filtres, tri, pagination, export catalogue et accès modification.', route('catalog', ['panel' => 'articles'])),
                    $feature('Liste des services', 'Services sans stock réel avec prix, catégorie et informations de vente.', route('catalog', ['panel' => 'services'])),
                    $feature('Ajouter un article', 'Création livre ou produit avec ISBN, code-barres, prix, taxes, stock, seuil et visibilité.', route('catalog', ['panel' => 'ajouter'])),
                    $feature('Ajouter un service', 'Création de prestation ou service non physique utilisable en vente et caisse.', route('catalog', ['panel' => 'ajouter-service'])),
                    $feature('Import articles/services', 'Import Excel/CSV avec modèles exemple pour articles et services.', route('catalog', ['panel' => 'import', 'kind' => 'items'])),
                    $feature('Catégories', 'Référentiel de familles, descriptions, hiérarchie et import côté catalogue.', route('catalog', ['panel' => 'categories'])),
                    $feature('Marques / éditeurs', 'Gestion des marques, éditeurs, contacts et type fournisseur/éditeur.', route('catalog', ['panel' => 'marques'])),
                    $feature('Unités', 'Unités de mesure utilisées par articles, achats et services.', route('catalog', ['panel' => 'unites'])),
                    $feature('Impôts / TVA', 'Taux fiscaux actifs utilisés sur le catalogue, la caisse et les documents.', route('catalog', ['panel' => 'impots'])),
                    $feature('Variantes', 'Options de variante et suivi des articles ayant des variantes.', route('catalog', ['panel' => 'variantes'])),
                    $feature('Étiquettes code-barres', 'Workbench d impression d étiquettes prix/code-barres avec sélection d articles.', route('catalog.labels')),
                    $feature('Recherche rapide produit', 'Recherche globale topbar par article, ISBN, code-barres et SKU.', route('catalog')),
                ],
            ],
            [
                'title' => 'Stock',
                'description' => 'Ajustements, transferts, inventaire et mouvements.',
                'features' => [
                    $feature('Ajustement de stock', 'Correction multi-lignes avec raison, entrepôt, ajout/retrait/définition et recherche article.', route('stock', ['panel' => 'stock-adjustment-add'])),
                    $feature('Historique des ajustements', 'Liste filtrable des corrections avec détail des lignes et quantités.', route('stock', ['panel' => 'stock-adjustments'])),
                    $feature('Transfert de stock', 'Déplacement d articles entre magasins, dépôts ou rayons.', route('stock', ['panel' => 'stock-transfer-add'])),
                    $feature('Historique des transferts', 'Suivi des transferts, statuts et quantités déplacées.', route('stock', ['panel' => 'stock-transfers'])),
                    $feature('Inventaire et mouvements', 'Tables de mouvements de stock et valorisation achat/vente disponibles dans le workspace stock.', route('stock', ['panel' => 'stock-adjustments'])),
                    $feature('Alertes stock', 'Détection bas stock/rupture et options POS liées au stock.', route('module', ['module' => 'settings', 'section' => 'store'])),
                ],
            ],
            [
                'title' => 'Caisse POS',
                'description' => 'Encaissement comptoir, tickets, remises et tiroir.',
                'features' => [
                    $feature('Point de vente', 'Recherche/scanner, filtres produits, panier, client, paiement et validation ticket.', route('pos')),
                    $feature('Tickets en attente', 'Mise en attente, reprise et suppression de tickets POS.', route('pos')),
                    $feature('Remises et coupons POS', 'Remise manuelle et vérification de coupons avant paiement.', route('pos')),
                    $feature('Ticket reçu', 'Aperçu thermique, impression, PDF vente et partage WhatsApp après paiement.', route('pos')),
                    $feature('Paramètres caisse', 'Prix modifiable, oversell, affichage rupture, seuil stock, tiroir navbar et règles d inventaire.', route('module', ['module' => 'settings', 'section' => 'store'])),
                    $feature('Verrouillage session', 'Verrouillage/déverrouillage par PIN ou mot de passe pour sécuriser la caisse.', route('session.locked')),
                ],
            ],
            [
                'title' => 'Ventes',
                'description' => 'Ventes manuelles, paiements, retours, livraisons, factures et devis.',
                'features' => [
                    $feature('Ajouter une vente', 'Panier, contrôle du stock et paiement centralisés dans la caisse.', route('pos')),
                    $feature('Liste des ventes', 'Historique filtrable avec détail, PDF, facture, remboursement et suppression.', route('module', 'sales')),
                    $feature('Paiements des ventes', 'Liste et ajout d encaissements par espèce, carte, virement ou avance client.', route('module', ['module' => 'sales', 'section' => 'payments'])),
                    $feature('Retours de vente', 'Création et suivi des remboursements avec option de restock.', route('module', ['module' => 'sales', 'section' => 'returns'])),
                    $feature('Livraisons', 'Bons de livraison, statuts, adresses et ventes à expédier.', route('module', ['module' => 'sales', 'section' => 'delivery'])),
                    $feature('Factures', 'Liste des factures de vente, recherche et téléchargement PDF.', route('module', ['module' => 'sales', 'section' => 'invoices'])),
                    $feature('Nouveau devis', 'Création d offre client sans impact stock avant conversion.', route('module', ['module' => 'sales', 'section' => 'quote-add'])),
                    $feature('Liste des devis', 'Suivi des devis, statuts, détail et conversion en vente.', route('module', ['module' => 'sales', 'section' => 'quotes'])),
                ],
            ],
            [
                'title' => 'Achats',
                'description' => 'Commandes fournisseurs, réception, PDF et retours.',
                'features' => [
                    $feature('Nouvel achat', 'Commande fournisseur multi-lignes avec entrepôt, échéance, taxes, frais et notes.', route('module', ['module' => 'purchases', 'section' => 'add'])),
                    $feature('Liste des achats', 'Suivi filtrable des commandes, détail, réception stock et PDF.', route('module', ['module' => 'purchases', 'section' => 'list'])),
                    $feature('Réception achat', 'Confirmation de réception et mise à jour du stock/coût selon paramètres.', route('module', ['module' => 'purchases', 'section' => 'list'])),
                    $feature('Retours achat', 'Création et suivi des retours fournisseur avec sources d achat reçues.', route('module', ['module' => 'purchases', 'section' => 'returns'])),
                    $feature('Paiements achat', 'Routes de rapports et libellés existent, mais aucun écran de saisie dédié n est visible.', route('module', ['module' => 'reports', 'section' => 'purchase-payments']), 'review'),
                ],
            ],
            [
                'title' => 'Contacts CRM',
                'description' => 'Clients, fournisseurs, soldes, imports et segmentation.',
                'features' => [
                    $feature('Ajouter un client', 'Fiche client avec coordonnées, CIN, crédit, avance, solde, adhésion, niveau de prix et adresses.', route('module', ['module' => 'contacts', 'section' => 'customer-add'])),
                    $feature('Liste des clients', 'Recherche, filtres, soldes, édition et accès aux informations CRM.', route('module', ['module' => 'contacts', 'section' => 'customers'])),
                    $feature('Importer des clients', 'Import de contacts clients via fichier exemple.', route('module', ['module' => 'contacts', 'section' => 'import-customers'])),
                    $feature('Ajouter un fournisseur', 'Fiche fournisseur avec fiscalité, solde précédent, adresses et coordonnées.', route('module', ['module' => 'contacts', 'section' => 'supplier-add'])),
                    $feature('Liste des fournisseurs', 'Gestion des fournisseurs, soldes achats/retours et édition.', route('module', ['module' => 'contacts', 'section' => 'suppliers'])),
                    $feature('Importer des fournisseurs', 'Import de contacts fournisseurs via fichier exemple.', route('module', ['module' => 'contacts', 'section' => 'import-suppliers'])),
                ],
            ],
            [
                'title' => 'Finances',
                'description' => 'Avances, coupons, dépenses, comptes et mouvements de caisse.',
                'features' => [
                    $feature('Ajouter une avance', 'Enregistrement des acomptes clients et impact sur le solde d avance.', route('module', ['module' => 'finance', 'section' => 'advance-add'])),
                    $feature('Liste des avances', 'Historique filtrable des avances et balances clients.', route('module', ['module' => 'finance', 'section' => 'advances'])),
                    $feature('Créer un coupon client', 'Coupon lié à un client avec montant, dates et suivi usage.', route('module', ['module' => 'finance', 'section' => 'customer-coupon-add'])),
                    $feature('Coupons client', 'Liste des coupons affectés aux clients.', route('module', ['module' => 'finance', 'section' => 'customer-coupons'])),
                    $feature('Créer un coupon', 'Création de coupon de remise global utilisable en caisse.', route('module', ['module' => 'finance', 'section' => 'coupon-add'])),
                    $feature('Maître des coupons', 'Gestion des coupons actifs, expirations, montants utilisés et suppression.', route('module', ['module' => 'finance', 'section' => 'coupons'])),
                    $feature('Ajouter un compte', 'Création comptes banque, caisse ou TPE par magasin.', route('module', ['module' => 'finance', 'section' => 'account-add'])),
                    $feature('Liste des comptes', 'Soldes, comptes actifs et édition/suppression des comptes financiers.', route('module', ['module' => 'finance', 'section' => 'accounts'])),
                    $feature('Dépôts', 'Enregistrement et liste des dépôts vers comptes financiers.', route('module', ['module' => 'finance', 'section' => 'deposits'])),
                    $feature('Transferts argent', 'Transfert entre comptes avec trace de transaction.', route('module', ['module' => 'finance', 'section' => 'transfers'])),
                    $feature('Transactions espèces', 'Mouvements des comptes de type caisse et historique associé.', route('module', ['module' => 'finance', 'section' => 'cash'])),
                    $feature('Tiroir caisse', 'Ouverture, mouvements, clôture, solde attendu et historique par magasin.', route('module', 'cash-register')),
                    $feature('Ajouter une dépense', 'Saisie frais/charges avec catégorie, paiement, référence et note.', route('module', ['module' => 'finance', 'section' => 'expense-add'])),
                    $feature('Liste des dépenses', 'Recherche et filtres par catégorie, période, paiement et détail.', route('module', ['module' => 'finance', 'section' => 'expenses'])),
                    $feature('Catégories de dépenses', 'Création et liste des catégories avec couleur et description.', route('module', ['module' => 'finance', 'section' => 'expense-categories'])),
                ],
            ],
            [
                'title' => 'Rapports',
                'description' => 'Analytique filtrable, impression/PDF et copie de tableau.',
                'features' => [
                    $feature('Profit et pertes', 'Synthèse ventes nettes, coûts, dépenses, marge et profit net.', route('module', ['module' => 'reports', 'section' => 'profit-loss'])),
                    $feature('Ventes et paiements', 'Rapports de ventes, paiements, tickets, clients et statuts.', route('module', ['module' => 'reports', 'section' => 'sales-payments'])),
                    $feature('Commandes client', 'Onglet rapport présent, actuellement alimenté par la vue générique ventes.', route('module', ['module' => 'reports', 'section' => 'customer-orders']), 'review'),
                    $feature('Ventes / récapitulatif', 'Rapports ventes, résumé ventes et articles vendus.', route('module', ['module' => 'reports', 'section' => 'sales-summary'])),
                    $feature('Retours ventes', 'Retours, articles retournés et paiements retours.', route('module', ['module' => 'reports', 'section' => 'sales-return'])),
                    $feature('Achats', 'Rapport achat, retours achat, articles fournisseur et taxes achat.', route('module', ['module' => 'reports', 'section' => 'purchases'])),
                    $feature('Dépenses', 'Rapport dépenses filtrable par période et recherche.', route('module', ['module' => 'reports', 'section' => 'expenses'])),
                    $feature('Stock', 'Rapport de stock et transferts de stock.', route('module', ['module' => 'reports', 'section' => 'stock'])),
                    $feature('Taxes/GST', 'Onglets taxe vente/achat, GSTR et TPS présents avec données agrégées génériques.', route('module', ['module' => 'reports', 'section' => 'sales-tax']), 'review'),
                    $feature('Points vendeur', 'Onglet visible dans les rapports, source de données spécialisée non identifiée.', route('module', ['module' => 'reports', 'section' => 'seller-points']), 'review'),
                ],
            ],
            [
                'title' => 'Paramètres et administration',
                'description' => 'Configuration société, magasins, sécurité, référentiels et intégrations.',
                'features' => [
                    $feature('Société', 'Profil magasin, fiscalité, formats, devise, numérotation, documents et conditions.', route('module', ['module' => 'settings', 'section' => 'company'])),
                    $feature('Magasins / dépôts', 'Catalogue magasins, dépôt, rayon, magasin courant et activation.', route('module', ['module' => 'settings', 'section' => 'warehouses'])),
                    $feature('Caisse & stock', 'Règles POS, stock, seuils, coût achat, inventaire et tiroir navbar.', route('module', ['module' => 'settings', 'section' => 'store'])),
                    $feature('PDF', 'Réglages documents PDF, branding et informations société liées.', route('module', ['module' => 'settings', 'section' => 'documents'])),
                    $feature('Utilisateurs', 'Gestion des accès utilisateurs, rôles, magasins, permissions directes, PIN et photo.', route('module', ['module' => 'settings', 'section' => 'users'])),
                    $feature('Rôles', 'Création de rôles et attribution des permissions métier.', route('module', ['module' => 'settings', 'section' => 'roles'])),
                    $feature('Taxes', 'Référentiel taxes côté paramètres.', route('module', ['module' => 'settings', 'section' => 'taxes'])),
                    $feature('Unités', 'Référentiel unités côté paramètres.', route('module', ['module' => 'settings', 'section' => 'units'])),
                    $feature('Types de paiement', 'Référentiel modes de paiement actifs.', route('module', ['module' => 'settings', 'section' => 'payment-types'])),
                    $feature('Pays et états', 'Référentiels pays/régions utilisés dans les fiches.', route('module', ['module' => 'settings', 'section' => 'countries'])),
                    $feature('Mot de passe', 'Changement du mot de passe utilisateur connecté.', route('module', ['module' => 'settings', 'section' => 'password'])),
                    $feature('Messagerie', 'Configuration, envoi manuel, modèles, canaux SMS/WhatsApp et outbox.', route('module', ['module' => 'settings', 'section' => 'messaging'])),
                    $feature('Thème', 'Préréglages visuels et personnalisation couleurs/densité/rayon.', route('module', ['module' => 'settings', 'section' => 'theme'])),
                    $feature('Matériel', 'Configuration imprimante thermique ESC/POS, tiroir-caisse et lecteur code-barres via Web Serial API.', route('module', ['module' => 'settings', 'section' => 'hardware'])),
                    $feature('Profil utilisateur', 'Informations personnelles, avatar et résumé activité.', route('profile')),
                    $feature('Journal d activité', 'Audit log filtrable par utilisateur, période, méthode et action pour propriétaire.', route('profile.activity')),
                ],
            ],
            [
                'title' => 'Code existe / non visible',
                'description' => 'Fonctionnalités présentes en route, modèle ou vue mais absentes ou peu exposées dans le menu principal.',
                'features' => [
                    $feature('Emprunts', 'Module loans, modèle Loan et rendu simple existent, mais aucun lien sidebar n est exposé.', route('module', 'loans'), 'code'),
                    $feature('Imports catégories/marques/variantes', 'Libellés d import existent côté catalogue; les routes principales importent surtout articles/services.', route('catalog', ['panel' => 'import', 'kind' => 'categories']), 'code'),
                    $feature('Routes legacy', 'Nombreux anciens chemins redirigent vers les nouveaux modules pour compatibilité.', route('dashboard'), 'code'),
                    $feature('Modèles ressources étendus', 'SaleInvoice, DeliveryOrder, CashRegisterSession, AccountTransaction, StockTransfer et autres modèles alimentent les écrans.', route('functionality-guide'), 'code'),
                ],
            ],
            [
                'title' => 'Missing / To Review',
                'description' => 'Éléments attendus ou présents en libellé mais incomplets, génériques ou non reliés clairement.',
                'features' => [
                    $feature('Paiements fournisseurs complets', 'Le reporting mentionne les paiements achat, mais aucun workflow de paiement fournisseur dédié n a été trouvé.', route('module', ['module' => 'reports', 'section' => 'purchase-payments']), 'review'),
                    $feature('Rapports fiscaux spécialisés', 'GSTR/TPS/taxes utilisent des tableaux génériques; vérifier les règles fiscales attendues.', route('module', ['module' => 'reports', 'section' => 'gstr-1']), 'review'),
                    $feature('Gestion emprunts complète', 'Le module prêts existe mais semble réduit à une liste sans création/retour/pénalité visible.', route('module', 'loans'), 'review'),
                    $feature('Exports modules', 'Des boutons Exporter sont visibles sur certains modules, mais les exports dédiés ne sont pas toujours câblés.', route('module', 'sales'), 'review'),
                    $feature('Permissions appliquées par écran', 'Rôles et permissions existent; vérifier l enforcement fin sur toutes les routes sensibles.', route('module', ['module' => 'settings', 'section' => 'roles']), 'review'),
                ],
            ],
        ];
    }

    public function catalog(Request $request): View
    {
        $tenant = $this->tenant();
        abort_unless(AppModules::enabled($tenant, 'catalog'), 404);
        $panel = $request->query('panel', 'articles');
        $query = trim((string) ($request->query('q') ?: data_get($request->input('search', []), 'value', '')));
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
        $stockItemSearch = trim((string) $request->query('stock_q'));
        $stockInventoryQuery = trim((string) $request->query('stock_inventory_q'));
        $stockInventoryItemId = (int) $request->query('stock_inventory_item');
        $stockInventoryState = $request->query('stock_inventory_state', 'all');
        $inventoryQuery = trim((string) $request->query('inventory_q'));
        $inventoryItemId = (int) $request->query('inventory_item');
        $movementType = $request->query('movement_type', 'all');
        $movementLocation = (int) $request->query('movement_location', 0);
        $currentStore = $this->currentStore($tenant);
        $inventoryService = app(\App\Services\Inventory\InventoryService::class);
        $currentStoreLocationId = $inventoryService->locationIdFromName($tenant->id, $currentStore['name'] ?? null);
        $currentStoreLocation = Location::where('tenant_id', $tenant->id)->whereKey($currentStoreLocationId)->first();
        $locationStockTable = (new ItemLocationStock())->getTable();
        $selectedInventoryItem = $inventoryItemId > 0
            ? $tenant->items()
                ->with(['category', 'brand', 'unit'])
                ->select('items.*')
                ->leftJoin($locationStockTable.' as selected_location_stock', function ($join) use ($tenant, $currentStoreLocationId): void {
                    $join->on('selected_location_stock.item_id', '=', 'items.id')
                        ->where('selected_location_stock.tenant_id', '=', $tenant->id)
                        ->where('selected_location_stock.location_id', '=', $currentStoreLocationId)
                        ->whereNull('selected_location_stock.variant_id');
                })
                ->selectRaw('coalesce(selected_location_stock.quantity, 0) as store_stock_quantity')
                ->selectRaw('coalesce(selected_location_stock.reserved_quantity, 0) as store_reserved_quantity')
                ->where('type', '!=', 'service')
                ->whereKey($inventoryItemId)
                ->first()
            : null;
        $selectedInventoryItemStockValue = $selectedInventoryItem
            ? (float) ItemLocationStock::where('tenant_id', $tenant->id)
                ->where('item_id', $selectedInventoryItem->id)
                ->where('location_id', $currentStoreLocationId)
                ->selectRaw('SUM(quantity * average_cost) as value')
                ->value('value') ?? 0
            : 0;
        $selectedInventoryItemReserved = $selectedInventoryItem
            ? (int) ItemLocationStock::where('tenant_id', $tenant->id)
                ->where('item_id', $selectedInventoryItem->id)
                ->where('location_id', $currentStoreLocationId)
                ->sum('reserved_quantity')
            : 0;
        // Selected item for the "Stock par article" ID picker (separate from the movement-history picker).
        $selectedStockItem = $stockInventoryItemId > 0
            ? $tenant->items()->select(['id', 'title'])->whereKey($stockInventoryItemId)->first()
            : null;
        $stockInventoryItems = $tenant->items()
            ->with(['category', 'brand', 'unit'])
            ->select('items.*')
            ->leftJoin($locationStockTable.' as inventory_location_stock', function ($join) use ($tenant, $currentStoreLocationId): void {
                $join->on('inventory_location_stock.item_id', '=', 'items.id')
                    ->where('inventory_location_stock.tenant_id', '=', $tenant->id)
                    ->where('inventory_location_stock.location_id', '=', $currentStoreLocationId)
                    ->whereNull('inventory_location_stock.variant_id');
            })
            ->selectRaw('coalesce(inventory_location_stock.quantity, 0) as store_stock_quantity')
            ->selectRaw('coalesce(inventory_location_stock.reserved_quantity, 0) as store_reserved_quantity')
            ->selectRaw('coalesce(inventory_location_stock.average_cost, items.purchase_price, 0) as store_average_cost')
            ->selectSub(function ($builder) use ($tenant, $currentStoreLocationId): void {
                $builder->from('stock_movements')
                    ->selectRaw('count(*)')
                    ->whereColumn('stock_movements.item_id', 'items.id')
                    ->where('stock_movements.tenant_id', $tenant->id)
                    ->where('stock_movements.location_id', $currentStoreLocationId);
            }, 'stock_movements_count')
            ->where('items.type', '!=', 'service')
            ->when($stockInventoryItemId > 0, fn (Builder $builder) => $builder->whereKey($stockInventoryItemId))
            ->when($stockInventoryItemId === 0 && $stockInventoryQuery !== '', fn (Builder $builder) => $builder->where(function (Builder $builder) use ($stockInventoryQuery): void {
                $builder->where('items.title', 'like', "%{$stockInventoryQuery}%")
                    ->orWhere('items.item_code', 'like', "%{$stockInventoryQuery}%")
                    ->orWhere('items.sku', 'like', "%{$stockInventoryQuery}%")
                    ->orWhere('items.isbn', 'like', "%{$stockInventoryQuery}%")
                    ->orWhere('items.barcode', 'like', "%{$stockInventoryQuery}%")
                    ->orWhere('items.location', 'like', "%{$stockInventoryQuery}%");
            }))
            ->when($stockInventoryState === 'low', fn (Builder $builder) => $builder->whereRaw('coalesce(inventory_location_stock.quantity, 0) <= items.min_stock_threshold')->whereRaw('coalesce(inventory_location_stock.quantity, 0) > 0'))
            ->when($stockInventoryState === 'out', fn (Builder $builder) => $builder->whereRaw('coalesce(inventory_location_stock.quantity, 0) <= 0'))
            ->when($stockInventoryState === 'available', fn (Builder $builder) => $builder->whereRaw('coalesce(inventory_location_stock.quantity, 0) > 0'))
            ->orderByRaw('case when coalesce(inventory_location_stock.quantity, 0) <= items.min_stock_threshold then 0 else 1 end')
            ->orderBy('items.title')
            ->paginate(15, ['*'], 'inventory_page')
            ->withQueryString();
        $stockAdjustments = $this->stockAdjustmentsQuery($tenant, $request)->paginate($perPage, ['*'], 'adjustments_page')->withQueryString();
        $stockTransfers = $this->stockTransfersQuery($tenant, $request)->paginate($perPage, ['*'], 'transfers_page')->withQueryString();
        $stocktakes = $this->stocktakesQuery($tenant, $request)->paginate($perPage, ['*'], 'stocktakes_page')->withQueryString();
        $stockMovementsQuery = DB::table('stock_movements')
            ->join('items', 'stock_movements.item_id', '=', 'items.id')
            ->leftJoin('users', 'stock_movements.user_id', '=', 'users.id')
            ->leftJoin('locations', 'stock_movements.location_id', '=', 'locations.id')
            ->leftJoin('item_variants', 'stock_movements.variant_id', '=', 'item_variants.id')
            ->where('stock_movements.tenant_id', $tenant->id)
            ->when($inventoryItemId > 0, fn ($builder) => $builder->where('stock_movements.item_id', $inventoryItemId))
            ->when($inventoryQuery !== '', function ($builder) use ($inventoryQuery): void {
                $builder->where(function ($builder) use ($inventoryQuery): void {
                    $builder->where('items.title', 'like', "%{$inventoryQuery}%")
                        ->orWhere('items.item_code', 'like', "%{$inventoryQuery}%")
                        ->orWhere('items.barcode', 'like', "%{$inventoryQuery}%")
                        ->orWhere('items.isbn', 'like', "%{$inventoryQuery}%")
                        ->orWhere('stock_movements.type', 'like', "%{$inventoryQuery}%")
                        ->orWhere('stock_movements.note', 'like', "%{$inventoryQuery}%");
                });
            })
            ->when($movementType !== 'all' && $movementType !== '', fn ($builder) => $builder->where('stock_movements.type', $movementType))
            ->where('stock_movements.location_id', $movementLocation > 0 ? $movementLocation : $currentStoreLocationId);

        $stockMovementCount = (clone $stockMovementsQuery)->count('stock_movements.id');
        $stockMovementPerPage = min(max((int) $request->query('movement_per_page', 50), 20), 100);

        $stockMovements = $stockMovementsQuery
            ->select([
                'stock_movements.id',
                'stock_movements.item_id',
                'stock_movements.variant_id',
                'stock_movements.location_id',
                'stock_movements.type',
                'stock_movements.quantity_before',
                'stock_movements.quantity_delta',
                'stock_movements.quantity_after',
                'stock_movements.unit_cost',
                'stock_movements.total_cost',
                'stock_movements.reference_type',
                'stock_movements.reference_id',
                'stock_movements.reference_number',
                'stock_movements.note',
                'stock_movements.created_at',
                'items.title as item_title',
                'items.item_code',
                'items.barcode',
                'items.purchase_price',
                'items.sale_price',
                'items.stock_quantity',
                'users.name as user_name',
                'locations.name as location_name',
                'item_variants.name as variant_name',
            ])
            ->latest('stock_movements.created_at')
            ->paginate($stockMovementPerPage, ['*'], 'movements_page')
            ->withQueryString();

        $itemsQuery = $this->catalogItemsQuery($tenant, $request);
        if (in_array($panel, ['services', 'ajouter-service'], true)) {
            $type = 'service';
        }

        $items = $itemsQuery
            ->orderBy($sort, $direction)
            ->paginate($perPage)
            ->withQueryString();

        $editItem = null;
        $editItemLocationStock = collect();
        if ($request->filled('edit')) {
            $editItem = $tenant->items()
                ->with(['category', 'brand', 'unit', 'tax', 'variants'])
                ->whereKey((int) $request->query('edit'))
                ->first();

            if ($editItem && $editItem->type !== 'service') {
                $editItemLocationStock = ItemLocationStock::query()
                    ->with('location')
                    ->where('tenant_id', $tenant->id)
                    ->where('item_id', $editItem->id)
                    ->whereNull('variant_id')
                    ->orderByDesc('quantity')
                    ->get();
            }
        }

        return view('librairepro.catalog', [
            'tenant' => $tenant,
            'active' => $request->routeIs('stock') ? 'stock' : 'catalog',
            'items' => $items,
            'categories' => Category::where('tenant_id', $tenant->id)->with(['parent'])->withCount(['items', 'children'])->orderBy('name')->get(),
            'brands' => Brand::where('tenant_id', $tenant->id)->orderBy('name')->get(),
            'units' => Unit::where('tenant_id', $tenant->id)->orderBy('name')->get(),
            'taxes' => Tax::where('tenant_id', $tenant->id)->orderBy('name')->get(),
            'categoryList' => Category::where('tenant_id', $tenant->id)
                ->with(['parent', 'children'])
                ->withCount(['items', 'children'])
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
                ->select('items.*')
                ->leftJoin($locationStockTable.' as stock_item_location_stock', function ($join) use ($tenant, $currentStoreLocationId): void {
                    $join->on('stock_item_location_stock.item_id', '=', 'items.id')
                        ->where('stock_item_location_stock.tenant_id', '=', $tenant->id)
                        ->where('stock_item_location_stock.location_id', '=', $currentStoreLocationId)
                        ->whereNull('stock_item_location_stock.variant_id');
                })
                ->selectRaw('coalesce(stock_item_location_stock.quantity, 0) as store_stock_quantity')
                ->with(['category', 'brand'])
                ->where('items.type', '!=', 'service')
                ->when($stockItemSearch !== '', fn (Builder $builder) => $builder->where(function (Builder $builder) use ($stockItemSearch): void {
                    $builder->where('items.title', 'like', "%{$stockItemSearch}%")
                        ->orWhere('items.item_code', 'like', "%{$stockItemSearch}%")
                        ->orWhere('items.sku', 'like', "%{$stockItemSearch}%")
                        ->orWhere('items.isbn', 'like', "%{$stockItemSearch}%")
                        ->orWhere('items.barcode', 'like', "%{$stockItemSearch}%")
                        ->orWhere('items.custom_barcode1', 'like', "%{$stockItemSearch}%");
                }))
                ->orderBy('items.title')
                ->take($stockItemSearch !== '' ? 80 : 500)
                ->get(),
            'stockAdjustments' => $stockAdjustments,
            'stockTransfers' => $stockTransfers,
            'stocktakes' => $stocktakes,
            'locations' => Location::where('tenant_id', $tenant->id)->where('is_active', true)->orderBy('name')->get(),
            'stockInventoryItems' => $stockInventoryItems,
            'stockInventoryQuery' => $stockInventoryQuery,
            'stockInventoryItemId' => $stockInventoryItemId,
            'selectedStockItem' => $selectedStockItem,
            'stockInventoryState' => $stockInventoryState,
            'selectedInventoryItem' => $selectedInventoryItem,
            'selectedInventoryItemStockValue' => $selectedInventoryItemStockValue,
            'selectedInventoryItemReserved' => $selectedInventoryItemReserved,
            'stockMovements' => $stockMovements,
            'movementType' => $movementType,
            'movementLocation' => $movementLocation,
            'stores' => $this->storeCatalog($tenant),
            'currentStore' => $currentStore,
            'currentStoreLocation' => $currentStoreLocation,
            'suggestedItemCode' => $this->nextItemCode($tenant->id),
            'stockStats' => [
                'adjustments' => StockAdjustment::where('tenant_id', $tenant->id)->count(),
                'transfers' => StockTransfer::where('tenant_id', $tenant->id)->count(),
                'stocktakes' => Stocktake::where('tenant_id', $tenant->id)->count(),
                'locations' => Location::where('tenant_id', $tenant->id)->where('is_active', true)->count(),
                'adjusted_month' => StockAdjustment::where('tenant_id', $tenant->id)->whereDate('adjusted_at', '>=', now()->startOfMonth())->sum('total_quantity'),
                'transferred_month' => StockTransfer::where('tenant_id', $tenant->id)->whereDate('transferred_at', '>=', now()->startOfMonth())->sum('total_quantity'),
                'stock_units' => (int) ItemLocationStock::where('tenant_id', $tenant->id)->where('location_id', $currentStoreLocationId)->sum('quantity'),
                'reserved_units' => (int) ItemLocationStock::where('tenant_id', $tenant->id)->where('location_id', $currentStoreLocationId)->sum('reserved_quantity'),
                'stock_purchase_value' => (float) DB::table($locationStockTable.' as store_stock')
                    ->join('items', 'store_stock.item_id', '=', 'items.id')
                    ->where('store_stock.tenant_id', $tenant->id)
                    ->where('store_stock.location_id', $currentStoreLocationId)
                    ->where('items.type', '!=', 'service')
                    ->selectRaw('sum(store_stock.quantity * coalesce(store_stock.average_cost, items.purchase_price, 0)) as value')
                    ->value('value'),
                'stock_sale_value' => (float) DB::table($locationStockTable.' as store_stock')
                    ->join('items', 'store_stock.item_id', '=', 'items.id')
                    ->where('store_stock.tenant_id', $tenant->id)
                    ->where('store_stock.location_id', $currentStoreLocationId)
                    ->where('items.type', '!=', 'service')
                    ->selectRaw('sum(store_stock.quantity * items.sale_price) as value')
                    ->value('value'),
                'movement_count' => $stockMovementCount,
            ],
            'editItem' => $editItem,
            'editItemLocationStock' => $editItemLocationStock,
            'catalogStats' => [
                'items' => $tenant->items()->where('type', '!=', 'service')->count(),
                'services' => $tenant->items()->where('type', 'service')->count(),
                'low' => DB::table($locationStockTable.' as store_stock')
                    ->join('items', 'store_stock.item_id', '=', 'items.id')
                    ->where('store_stock.tenant_id', $tenant->id)
                    ->where('store_stock.location_id', $currentStoreLocationId)
                    ->where('items.type', '!=', 'service')
                    ->whereRaw('store_stock.quantity <= items.min_stock_threshold')
                    ->count(),
                'value' => DB::table($locationStockTable.' as store_stock')
                    ->join('items', 'store_stock.item_id', '=', 'items.id')
                    ->where('store_stock.tenant_id', $tenant->id)
                    ->where('store_stock.location_id', $currentStoreLocationId)
                    ->selectRaw('sum(store_stock.quantity * coalesce(store_stock.average_cost, items.purchase_price, 0)) as value')
                    ->value('value') ?? 0,
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
            'inventoryQuery' => $inventoryQuery,
            'inventoryItemId' => $inventoryItemId,
        ]);
    }

    public function stock(Request $request): View
    {
        abort_unless(AppModules::enabled($this->tenant(), 'stock'), 404);

        if (! $request->query('panel')) {
            $request->query->set('panel', 'stock-adjustments');
        }

        return $this->catalog($request);
    }

    public function catalogData(Request $request): \Illuminate\Http\JsonResponse
    {
        $tenant = $this->tenant();
        abort_unless(AppModules::enabled($tenant, 'catalog'), 404);
        $panel = $request->query('panel', 'articles');

        return DataTables::eloquent($this->catalogItemsQuery($tenant, $request))
            ->filter(function (Builder $builder) use ($request): void {
                $search = trim((string) data_get($request->input('search', []), 'value'));
                if ($search === '') {
                    return;
                }

                $builder->where(function (Builder $builder) use ($search): void {
                    $builder->where('items.title', 'like', "%{$search}%")
                        ->orWhere('items.item_code', 'like', "%{$search}%")
                        ->orWhere('items.sku', 'like', "%{$search}%")
                        ->orWhere('items.isbn', 'like', "%{$search}%")
                        ->orWhere('items.barcode', 'like', "%{$search}%")
                        ->orWhere('items.custom_barcode1', 'like', "%{$search}%")
                        ->orWhere('items.author', 'like', "%{$search}%")
                        ->orWhere('items.editor', 'like', "%{$search}%")
                        ->orWhere('items.description', 'like', "%{$search}%")
                        ->orWhere('catalog_categories.name', 'like', "%{$search}%")
                        ->orWhere('catalog_brands.name', 'like', "%{$search}%")
                        ->orWhere('catalog_units.name', 'like', "%{$search}%")
                        ->orWhere('catalog_taxes.name', 'like', "%{$search}%");
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
            ->editColumn('barcode', fn (Item $item): string => '<span class="catalog-code-cell">'.e($item->barcode ?? $item->isbn ?? $item->sku ?? '—').'</span>')
            ->editColumn('title', function (Item $item): string {
                $brand = $item->brand?->name ? ' · '.$item->brand->name : '';
                $variants = $item->variants->isNotEmpty() ? '<p class="mt-1 text-xs font-medium text-brand">'.$item->variants->count().' variante(s)</p>' : '';

                return '<div class="catalog-item-title-cell"><p>'.e($item->title).'</p><small>'.e($item->item_code ?? 'Sans code interne').e($brand).'</small>'.$variants.'</div>';
            })
            ->addColumn('category_type', fn (Item $item): string => '<span class="catalog-main-text">'.e($item->category?->name ?? 'Sans catégorie').'</span><span class="catalog-sub-text">'.e($this->typeLabel($item->type)).'</span>')
            ->addColumn('unit_label', fn (Item $item): string => e($item->unit?->name ?? '—'))
            ->editColumn('stock_quantity', fn (Item $item): string => '<span class="catalog-stock-badge '.($item->is_low_stock && $item->type !== 'service' ? 'is-warning' : 'is-ok').'">'.($item->type === 'service' ? 'Illimité' : number_format($item->stock_quantity, 0, ',', ' ')).'</span>')
            ->editColumn('min_stock_threshold', fn (Item $item): string => $item->type === 'service' ? '—' : number_format($item->min_stock_threshold, 0, ',', ' '))
            ->editColumn('sale_price', fn (Item $item): string => '<strong>'.$this->money($item->sale_price).'</strong>')
            ->addColumn('tax_label', fn (Item $item): string => e($item->tax ? $item->tax->name.' ('.number_format((float) $item->tax->rate, 2, ',', ' ').'%)' : '—'))
            ->editColumn('status', function (Item $item): string {
                $isEnabled = (bool) $item->is_enabled && $item->status !== 'archived';
                $checkoutVisible = (bool) $item->checkout_visible;
                $onlineVisible = (bool) $item->online_store_visible;
                $enabledTone = $isEnabled
                    ? 'bg-emerald-50 text-emerald-700 ring-emerald-200'
                    : 'bg-slate-100 text-slate-700 ring-slate-200';
                $stateLabel = $item->type === 'service' ? 'Service' : $this->statusLabel($item->status);
                $stateTone = $item->type === 'service'
                    ? 'bg-violet-50 text-violet-700 ring-violet-200'
                    : match ($item->status) {
                        'out_of_stock' => 'bg-rose-50 text-rose-700 ring-rose-200',
                        'archived' => 'bg-slate-100 text-slate-700 ring-slate-200',
                        default => 'bg-blue-50 text-blue-700 ring-blue-200',
                    };
                $checkoutTone = $checkoutVisible && $isEnabled
                    ? 'bg-blue-50 text-blue-700 ring-blue-200'
                    : 'bg-amber-50 text-amber-700 ring-amber-200';
                $checkoutLabel = $checkoutVisible && $isEnabled ? 'Visible caisse' : 'Caché caisse';
                $onlineTone = $onlineVisible && $isEnabled
                    ? 'bg-indigo-50 text-indigo-700 ring-indigo-200'
                    : 'bg-slate-100 text-slate-700 ring-slate-200';
                $onlineLabel = $onlineVisible && $isEnabled ? 'Visible boutique' : 'Caché boutique';

                return '<div class="catalog-status-stack">'
                    .'<span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset '.$enabledTone.'">'.e($isEnabled ? 'Activé' : 'Désactivé').'</span>'
                    .'<span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset '.$stateTone.'">'.e($stateLabel).'</span>'
                    .'<span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset '.$checkoutTone.'">'.e($checkoutLabel).'</span>'
                    .'<span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset '.$onlineTone.'">'.e($onlineLabel).'</span>'
                    .'</div>';
            })
            ->addColumn('timestamps', fn (Item $item): string => '<div class="text-xs text-slate-500 whitespace-nowrap"><p title="Créé le">'.e($item->created_at?->copy()->setTimezone(TenantClock::timezone($tenant))->format('d/m/Y H:i') ?? '—').'</p><p class="mt-0.5" title="Modifié le">'.e($item->updated_at?->copy()->setTimezone(TenantClock::timezone($tenant))->format('d/m/Y H:i') ?? '—').'</p></div>')
            ->addColumn('action', fn (Item $item): string => '<div class="catalog-row-actions">'
                .'<a href="'.e(route('stock', ['panel' => 'stock-adjustments', 'inventory_item' => $item->id])).'#inventory-history" class="catalog-row-button is-muted">Historique</a>'
                .'<a href="'.e(route('catalog', ['panel' => $panel === 'services' ? 'services' : 'articles', 'edit' => $item->id])).'#edit-item" data-row-primary-action class="catalog-row-button is-primary">Modifier</a>'
                .'</div>')
            ->addColumn('row_url', fn (Item $item): string => route('catalog', ['panel' => $panel === 'services' ? 'services' : 'articles', 'edit' => $item->id]).'#edit-item')
            ->rawColumns(['checkbox', 'image', 'barcode', 'title', 'category_type', 'stock_quantity', 'sale_price', 'status', 'timestamps', 'action', 'row_url'])
            ->toJson();
    }

    public function storeStockAdjustment(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $requiresReason = (bool) data_get($tenant->settings, 'pos.require_adjustment_reason', true);
        $data = $request->validate([
            'adjusted_at' => ['nullable', 'date'],
            'warehouse' => ['nullable', 'string', 'max:120'],
            'reason' => [$requiresReason ? 'required' : 'nullable', 'string', 'max:160'],
            'note' => ['nullable', 'string', 'max:700'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['nullable', 'integer', Rule::exists('items', 'id')->where('tenant_id', $tenant->id)],
            'items.*.direction' => ['nullable', 'in:add,remove,set'],
            'items.*.quantity' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'items.*.note' => ['nullable', 'string', 'max:300'],
        ]);

        try {
            $adjustment = DB::transaction(function () use ($tenant, $data): StockAdjustment {
                $inventoryService = app(\App\Services\Inventory\InventoryService::class);
                $locationId = $inventoryService->locationIdFromName($tenant->id, $data['warehouse'] ?? null);
                $pendingLines = [];
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

                    if ($delta === 0) {
                        continue;
                    }

                    $pendingLines[] = [
                        'item' => $item,
                        'direction' => $direction,
                        'quantity' => $quantity,
                        'before' => $before,
                        'after' => $after,
                        'delta' => $delta,
                        'note' => $line['note'] ?? null,
                    ];
                    $totalQuantity += abs($delta);
                }

                if ($pendingLines === []) {
                    throw new \RuntimeException('Ajoutez au moins une ligne avec une quantité positive.');
                }

                $adjustment = StockAdjustment::create([
                    'tenant_id' => $tenant->id,
                    'number' => $this->nextStockAdjustmentNumber($tenant),
                    'status' => 'completed',
                    'warehouse' => $data['warehouse'] ?? null,
                    'reason' => $data['reason'] ?? null,
                    'total_quantity' => $totalQuantity,
                    'lines' => collect($pendingLines)->map(fn (array $line) => [
                        'item_id' => $line['item']->id,
                        'item_code' => $line['item']->item_code,
                        'name' => $line['item']->title,
                        'barcode' => $line['item']->barcode,
                        'direction' => $line['direction'],
                        'quantity' => $line['quantity'],
                        'quantity_before' => $line['before'],
                        'quantity_after' => $line['after'],
                        'quantity_delta' => $line['delta'],
                        'note' => $line['note'],
                    ])->all(),
                    'note' => $data['note'] ?? null,
                    'adjusted_at' => $data['adjusted_at'] ?? now(),
                ]);

                foreach ($pendingLines as $line) {
                    $inventoryService->move(new \App\Services\Inventory\MovementDTO(
                        tenantId: $tenant->id,
                        itemId: $line['item']->id,
                        variantId: null,
                        locationId: $locationId,
                        type: $line['direction'] === 'set'
                            ? \App\Services\Inventory\InventoryMovementType::CORRECTION
                            : \App\Services\Inventory\InventoryMovementType::ADJUSTMENT,
                        quantityChanged: $line['delta'],
                        referenceType: StockAdjustment::class,
                        referenceId: $adjustment->id,
                        referenceNumber: $adjustment->number,
                        note: trim(($data['reason'] ?? 'Ajustement stock').' '.($line['note'] ?? '')),
                        reason: $data['reason'] ?? null,
                    ));

                    $line['item']->update([
                        'stock_quantity' => $line['after'],
                        'status' => $line['after'] <= 0 ? 'out_of_stock' : ($line['item']->status === 'out_of_stock' ? 'active' : $line['item']->status),
                    ]);
                }

                return $adjustment;
            });
        } catch (\RuntimeException $exception) {
            return back()->withInput()->withErrors(['stock' => $exception->getMessage()]);
        }

        return redirect()
            ->route('stock', ['panel' => 'stock-adjustments', 'detail_adjustment' => $adjustment->id])
            ->with('status', 'Ajustement '.$adjustment->number.' enregistré.');
    }

    public function stockItemSearch(Request $request): JsonResponse
    {
        $tenant = $this->tenant();
        $query = trim((string) $request->query('q'));

        $items = $tenant->items()
            ->with(['category', 'brand', 'tax', 'unit'])
            ->where('type', '!=', 'service')
            ->where('is_enabled', true)
            ->when($query !== '', fn (Builder $builder) => $builder->where(function (Builder $builder) use ($query): void {
                $builder->where('title', 'like', "%{$query}%")
                    ->orWhere('item_code', 'like', "%{$query}%")
                    ->orWhere('sku', 'like', "%{$query}%")
                    ->orWhere('isbn', 'like', "%{$query}%")
                    ->orWhere('barcode', 'like', "%{$query}%")
                    ->orWhere('custom_barcode1', 'like', "%{$query}%")
                    ->orWhereHas('category', fn (Builder $category) => $category->where('name', 'like', "%{$query}%"))
                    ->orWhereHas('brand', fn (Builder $brand) => $brand->where('name', 'like', "%{$query}%"));
            }))
            ->orderByRaw("case when stock_quantity <= 0 then 2 when stock_quantity <= min_stock_threshold then 1 else 0 end")
            ->orderBy('title')
            ->limit($query === '' ? 80 : 120)
            ->get()
            ->map(fn (Item $item): array => $this->stockItemOptionPayload($item));

        return $this->noStoreJson([
            'items' => $items,
            'count' => $items->count(),
        ]);
    }

    public function productQuickSearch(Request $request): JsonResponse
    {
        $tenant = $this->tenant();
        $query = trim((string) $request->query('q'));
        $context = (string) $request->query('context', 'default');

        $items = $tenant->items()
            ->with(['category', 'brand', 'tax', 'unit'])
            ->when($context === 'variants', fn (Builder $builder) => $builder->where('type', '!=', 'service'))
            ->when($query !== '', fn (Builder $builder) => $builder->where(function (Builder $builder) use ($query): void {
                $builder->where('title', 'like', "%{$query}%")
                    ->orWhere('item_code', 'like', "%{$query}%")
                    ->orWhere('sku', 'like', "%{$query}%")
                    ->orWhere('isbn', 'like', "%{$query}%")
                    ->orWhere('barcode', 'like', "%{$query}%")
                    ->orWhere('custom_barcode1', 'like', "%{$query}%")
                    ->orWhere('author', 'like', "%{$query}%")
                    ->orWhere('editor', 'like', "%{$query}%")
                    ->orWhere('description', 'like', "%{$query}%")
                    ->orWhereHas('category', fn (Builder $category) => $category->where('name', 'like', "%{$query}%"))
                    ->orWhereHas('brand', fn (Builder $brand) => $brand->where('name', 'like', "%{$query}%"));
            }))
            ->orderByRaw("case when type = 'service' then 1 when stock_quantity <= 0 then 2 else 0 end")
            ->orderBy('title')
            ->limit($query === '' ? 12 : 20)
            ->get()
            ->map(fn (Item $item): array => [
                'id' => $item->id,
                'title' => $item->title,
                'type' => $item->type,
                'type_label' => match ($item->type) {
                    'service' => 'Service',
                    'book' => 'Livre',
                    default => 'Article',
                },
                'code' => $item->barcode ?: ($item->isbn ?: ($item->sku ?: $item->item_code)),
                'category' => $item->category?->name,
                'brand' => $item->brand?->name,
                'unit' => $item->unit?->name,
                'stock' => $item->type === 'service' ? null : (int) $item->stock_quantity,
                'status' => $item->status,
                'is_enabled' => (bool) $item->is_enabled,
                'checkout_visible' => (bool) $item->checkout_visible,
                'price' => $this->money($item->sale_price),
                'raw_price' => (float) $item->sale_price,
                'tax_rate' => (float) ($item->tax?->rate ?? 0),
                'tax_inclusive' => ($item->tax_type ?? 'Exclusive') === 'Inclusive',
                'url' => route('catalog', [
                    'panel' => $item->type === 'service' ? 'services' : 'articles',
                    'edit' => $item->id,
                ]).'#edit-item',
            ]);

        return $this->noStoreJson([
            'items' => $items,
            'count' => $items->count(),
        ]);
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
                $inventoryService = app(\App\Services\Inventory\InventoryService::class);
                $sourceName = $data['store_from'] ?? $data['warehouse_from'] ?? null;
                $destinationName = $data['store_to'] ?? $data['warehouse_to'] ?? null;
                $sourceLocationId = $inventoryService->locationIdFromName($tenant->id, $sourceName);
                $destinationLocationId = $inventoryService->locationIdFromName($tenant->id, $destinationName);

                $moveStock = $sourceLocationId !== $destinationLocationId;
                $pendingLines = [];
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

                    if ($moveStock) {
                        $availableAtSource = $inventoryService->available($tenant->id, $item->id, null, $sourceLocationId);

                        if ($availableAtSource < $quantity) {
                            throw new \RuntimeException('Stock insuffisant pour '.$item->title.' à l\'emplacement source. Disponible: '.$availableAtSource.'.');
                        }
                    } elseif ((int) $item->stock_quantity < $quantity) {
                        throw new \RuntimeException('Stock insuffisant pour '.$item->title.'. Disponible: '.$item->stock_quantity.'.');
                    }

                    $pendingLines[] = [
                        'item' => $item,
                        'quantity' => $quantity,
                        'note' => $line['note'] ?? null,
                    ];
                    $totalQuantity += $quantity;
                }

                if ($pendingLines === []) {
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
                    'lines' => collect($pendingLines)->map(fn (array $line) => [
                        'item_id' => $line['item']->id,
                        'item_code' => $line['item']->item_code,
                        'name' => $line['item']->title,
                        'barcode' => $line['item']->barcode,
                        'quantity' => $line['quantity'],
                        'available_stock' => (int) $line['item']->stock_quantity,
                        'note' => $line['note'],
                    ])->all(),
                    'note' => $data['note'] ?? null,
                    'transferred_at' => $data['transferred_at'] ?? now(),
                ]);

                if ($moveStock) {
                    foreach ($pendingLines as $line) {
                        $inventoryService->move(new \App\Services\Inventory\MovementDTO(
                            tenantId: $tenant->id,
                            itemId: $line['item']->id,
                            variantId: null,
                            locationId: $sourceLocationId,
                            type: \App\Services\Inventory\InventoryMovementType::TRANSFER_OUT,
                            quantityChanged: $line['quantity'],
                            referenceType: StockTransfer::class,
                            referenceId: $transfer->id,
                            referenceNumber: $transfer->number,
                            note: 'Transfert stock '.$transfer->number.($line['note'] ? ' · '.$line['note'] : ''),
                            reason: 'Transfert vers '.($destinationName ?: 'destination'),
                        ));

                        $inventoryService->move(new \App\Services\Inventory\MovementDTO(
                            tenantId: $tenant->id,
                            itemId: $line['item']->id,
                            variantId: null,
                            locationId: $destinationLocationId,
                            type: \App\Services\Inventory\InventoryMovementType::TRANSFER_IN,
                            quantityChanged: $line['quantity'],
                            referenceType: StockTransfer::class,
                            referenceId: $transfer->id,
                            referenceNumber: $transfer->number,
                            note: 'Transfert stock '.$transfer->number.($line['note'] ? ' · '.$line['note'] : ''),
                            reason: 'Transfert depuis '.($sourceName ?: 'source'),
                        ));
                    }
                } else {
                    foreach ($pendingLines as $line) {
                        $this->recordStockMovementSnapshot($tenant, (int) $line['item']->id, 'transfer', 0, (int) $line['item']->stock_quantity, StockTransfer::class, $transfer->id, 'Transfert stock '.$transfer->number);
                    }
                }

                return $transfer;
            });
        } catch (\RuntimeException $exception) {
            return back()->withInput()->withErrors(['stock' => $exception->getMessage()]);
        }

        return redirect()
            ->route('stock', ['panel' => 'stock-transfers', 'detail_transfer' => $transfer->id])
            ->with('status', 'Transfert '.$transfer->number.' enregistré.');
    }

    public function storeStocktake(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $data = $request->validate([
            'location_id' => ['required', 'integer', Rule::exists('locations', 'id')->where('tenant_id', $tenant->id)->where('is_active', true)],
            'note' => ['nullable', 'string', 'max:700'],
            'items' => ['nullable', 'array'],
            'items.*.item_id' => ['nullable', 'integer', Rule::exists('items', 'id')->where('tenant_id', $tenant->id)],
            'items.*.counted_quantity' => ['nullable', 'integer', 'min:0'],
        ]);

        $stocktake = DB::transaction(function () use ($tenant, $data): Stocktake {
            $inventoryService = app(\App\Services\Inventory\InventoryService::class);
            $locationId = (int) $data['location_id'];

            $stocktake = Stocktake::create([
                'tenant_id' => $tenant->id,
                'location_id' => $locationId,
                'user_id' => auth()->id(),
                'number' => $this->nextStocktakeNumber($tenant),
                'status' => 'in_progress',
                'note' => $data['note'] ?? null,
                'started_at' => now(),
                'metadata' => $this->creationActorMetadata(),
            ]);

            foreach ($data['items'] ?? [] as $line) {
                $itemId = (int) ($line['item_id'] ?? 0);
                if ($itemId <= 0) {
                    continue;
                }

                $expected = $inventoryService->quantity($tenant->id, $itemId, null, $locationId);
                StocktakeItem::create([
                    'tenant_id' => $tenant->id,
                    'stocktake_id' => $stocktake->id,
                    'item_id' => $itemId,
                    'expected_quantity' => $expected,
                    'counted_quantity' => isset($line['counted_quantity']) ? max(0, (int) $line['counted_quantity']) : null,
                ]);
            }

            return $stocktake;
        });

        return redirect()
            ->route('stock', ['panel' => 'stocktakes', 'detail_stocktake' => $stocktake->id])
            ->with('status', 'Inventaire '.$stocktake->number.' créé.');
    }

    public function updateStocktake(Request $request, Stocktake $stocktake): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless($stocktake->tenant_id === $tenant->id, 404);
        abort_unless(in_array($stocktake->status, ['draft', 'in_progress'], true), 403);

        $data = $request->validate([
            'counts' => ['required', 'array'],
            'counts.*' => ['nullable', 'integer', 'min:0'],
        ]);

        DB::transaction(function () use ($stocktake, $tenant, $data): void {
            $stocktake = Stocktake::where('tenant_id', $tenant->id)->whereKey($stocktake->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($stocktake->status, ['draft', 'in_progress'], true), 403);

            foreach ($data['counts'] as $itemId => $count) {
                $stocktakeItem = StocktakeItem::where('tenant_id', $tenant->id)
                    ->where('stocktake_id', $stocktake->id)
                    ->whereKey((int) $itemId)
                    ->first();

                if ($stocktakeItem) {
                    $stocktakeItem->update(['counted_quantity' => $count]);
                }
            }
        });

        return back()->with('status', 'Comptage mis à jour.');
    }

    public function completeStocktake(Request $request, Stocktake $stocktake): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless($stocktake->tenant_id === $tenant->id, 404);
        abort_unless(in_array($stocktake->status, ['draft', 'in_progress'], true), 403);

        $idempotencyKey = $this->idempotencyKey($request);
        $batchKey = 'stocktake-complete-'.$stocktake->id.'-'.sha1($idempotencyKey);

        $stocktake = DB::transaction(function () use ($stocktake, $tenant, $batchKey): Stocktake {
            $existing = \App\Models\InventoryMovement::query()
                ->where('idempotency_key', $batchKey)
                ->where('tenant_id', $tenant->id)
                ->first();

            if ($existing) {
                return $stocktake;
            }

            $stocktake = Stocktake::where('tenant_id', $tenant->id)->whereKey($stocktake->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($stocktake->status, ['draft', 'in_progress'], true), 403);

            $inventoryService = app(\App\Services\Inventory\InventoryService::class);
            $locationId = $stocktake->location_id;

            foreach ($stocktake->items as $line) {
                if ($line->counted_quantity === null || ! $line->item || $line->item->type === 'service') {
                    continue;
                }

                $difference = $line->difference();
                if ($difference === 0) {
                    continue;
                }

                $inventoryService->move(new \App\Services\Inventory\MovementDTO(
                    tenantId: $tenant->id,
                    itemId: $line->item->id,
                    variantId: $line->variant_id,
                    locationId: $locationId,
                    type: \App\Services\Inventory\InventoryMovementType::STOCKTAKE,
                    quantityChanged: $difference,
                    referenceType: Stocktake::class,
                    referenceId: $stocktake->id,
                    referenceNumber: $stocktake->number,
                    note: 'Inventaire '.$stocktake->number,
                    idempotencyKey: $batchKey.'-item-'.$line->id,
                ));

                $line->item->increment('stock_quantity', $difference);
            }

            $metadata = array_merge($stocktake->metadata ?? [], $this->actorMetadata('updated'), [
                'completed_by' => auth()->id(),
                'completed_by_name' => auth()->user()?->name,
                'completed_by_at' => now()->toIso8601String(),
            ]);

            $stocktake->update([
                'status' => 'completed',
                'completed_at' => now(),
                'metadata' => $metadata,
            ]);

            return $stocktake;
        });

        return redirect()
            ->route('stock', ['panel' => 'stocktakes', 'detail_stocktake' => $stocktake->id])
            ->with('status', 'Inventaire '.$stocktake->number.' terminé.');
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
            ->addColumn('purchase_due', fn (Contact $contact): string => '<span class="font-semibold text-rose-600">'.$this->money((float) ($contact->purchases_due_sum ?? 0) + (float) $contact->outstanding_balance).'</span>')
            ->addColumn('purchase_return_due', fn (Contact $contact): string => '<span class="font-semibold text-emerald-600">'.$this->money((float) ($contact->purchase_returns_due_sum ?? 0) + (float) $contact->advance_balance).'</span>')
            ->addColumn('supplier_total', fn (Contact $contact): string => '<span class="font-semibold">'.$this->money((float) $contact->opening_balance + (float) $contact->outstanding_balance + (float) ($contact->purchases_due_sum ?? 0) - (float) $contact->advance_balance - (float) ($contact->purchase_returns_due_sum ?? 0)).'</span>')
            ->editColumn('outstanding_balance', fn (Contact $contact): string => '<span class="'.((float) $contact->outstanding_balance > 0 ? 'font-semibold text-rose-600' : 'text-slate-500').'">'.$this->money($contact->outstanding_balance).'</span>')
            ->editColumn('advance_balance', fn (Contact $contact): string => '<span class="'.((float) $contact->advance_balance > 0 ? 'font-semibold text-emerald-600' : 'text-slate-500').'">'.$this->money($contact->advance_balance).'</span>')
            ->editColumn('status', fn (Contact $contact): string => '<span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset '.($contact->status === 'active' ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-slate-100 text-slate-700 ring-slate-200').'">'.e($contact->status === 'active' ? 'Actif' : 'Archivé').'</span>')
            ->addColumn('timestamps', fn (Contact $contact): string => '<div class="text-xs text-slate-500 whitespace-nowrap"><p title="Créé le">'.e($contact->created_at?->copy()->setTimezone(TenantClock::timezone($tenant))->format('d/m/Y H:i') ?? '—').'</p><p class="mt-0.5" title="Modifié le">'.e($contact->updated_at?->copy()->setTimezone(TenantClock::timezone($tenant))->format('d/m/Y H:i') ?? '—').'</p></div>')
            ->addColumn('action', function (Contact $contact): string {
                $editUrl = route('module', ['module' => 'contacts', 'section' => $contact->kind === 'supplier' ? 'supplier-add' : 'customer-add', 'edit' => $contact->id]);
                $deleteUrl = route('contacts.destroy', $contact);

                return '<div class="flex justify-end gap-2"><a href="'.e($editUrl).'#contact-form" class="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold dark:border-white/10">Modifier</a><form action="'.e($deleteUrl).'" method="POST" onsubmit="return confirm(\'Supprimer ou archiver ce contact ?\')"><input type="hidden" name="_token" value="'.e(csrf_token()).'"><input type="hidden" name="_method" value="DELETE"><button class="rounded-lg border border-rose-200 px-3 py-2 text-xs font-semibold text-rose-600 dark:border-rose-500/20" type="submit">Supprimer</button></form></div>';
            })
            ->addColumn('row_url', fn (Contact $contact): string => route('module', ['module' => 'contacts', 'section' => $contact->kind === 'supplier' ? 'supplier-add' : 'customer-add', 'edit' => $contact->id]).'#contact-form')
            ->rawColumns(['checkbox', 'name', 'credit_limit', 'previous_balance', 'purchase_due', 'purchase_return_due', 'supplier_total', 'outstanding_balance', 'advance_balance', 'status', 'timestamps', 'action', 'row_url']);

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

    public function purchasesData(Request $request): \Illuminate\Http\JsonResponse
    {
        $tenant = $this->tenant();

        $search = trim((string) data_get($request->input('search', []), 'value'));
        if (! $request->filled('q') && $search !== '') {
            $request->merge(['q' => $search]);
        }

        return DataTables::eloquent($this->purchasesQuery($tenant, $request))
            ->addColumn('number_display', function (Purchase $purchase): string {
                $url = route('module', ['module' => 'purchases', 'section' => 'list', 'detail_purchase' => $purchase->id]);
                $warehouse = data_get($purchase->metadata, 'warehouse', 'Magasin principal') ?: 'Magasin principal';

                return '<a href="'.e($url).'" class="font-semibold text-slate-950 hover:text-brand dark:text-white">'.e($purchase->number).'</a>'
                    .'<p class="mt-1 text-xs text-slate-500">'.e($warehouse).'</p>';
            })
            ->addColumn('date_display', function (Purchase $purchase): string {
                $orderedAt = $purchase->ordered_at?->format('d/m/Y') ?? '—';
                $expectedAt = $purchase->expected_at?->format('d/m/Y') ?? '—';

                return '<span class="font-medium">'.e($orderedAt).'</span>'
                    .'<p class="mt-1 text-xs text-slate-500">Prévu '.e($expectedAt).'</p>';
            })
            ->addColumn('supplier_display', function (Purchase $purchase): string {
                $phone = $purchase->supplier?->phone
                    ? '<p class="mt-1 text-xs text-slate-500">'.e($purchase->supplier->phone).'</p>'
                    : '';

                return '<span class="font-semibold">'.e($purchase->supplier?->name ?? '—').'</span>'.$phone;
            })
            ->addColumn('reference_display', function (Purchase $purchase): string {
                $invoice = data_get($purchase->metadata, 'supplier_invoice', '—') ?: '—';
                $reference = data_get($purchase->metadata, 'reference', 'Sans référence') ?: 'Sans référence';

                return '<span class="font-medium">'.e($invoice).'</span>'
                    .'<p class="mt-1 text-xs text-slate-500">'.e($reference).'</p>';
            })
            ->addColumn('created_by_display', function (Purchase $purchase) use ($tenant): string {
                $createdBy = data_get($purchase->metadata, 'created_by_name') ?: $purchase->user?->name ?: 'Utilisateur inconnu';
                $createdAt = data_get($purchase->metadata, 'created_by_at') ?: $purchase->created_at?->toIso8601String();
                $date = $createdAt ? Carbon::parse($createdAt)->setTimezone(TenantClock::timezone($tenant))->format('d/m/Y H:i') : '—';

                return '<span class="font-semibold">'.e($createdBy).'</span>'
                    .'<p class="mt-1 text-xs text-slate-500">'.e($date).'</p>';
            })
            ->addColumn('items_display', function (Purchase $purchase): string {
                $orderedQuantity = (int) $purchase->items->sum('quantity_ordered');
                $receivedQuantity = (int) $purchase->items->sum('quantity_received');

                return '<span class="font-semibold">'.e((string) $purchase->items->count()).' ligne(s)</span>'
                    .'<p class="mt-1 text-xs text-slate-500">'.e((string) $orderedQuantity).' commandé(s), '.e((string) $receivedQuantity).' reçu(s)</p>';
            })
            ->addColumn('receipt_display', function (Purchase $purchase): string {
                $orderedQuantity = (int) $purchase->items->sum('quantity_ordered');
                $receivedQuantity = (int) $purchase->items->sum('quantity_received');
                $remainingQuantity = max(0, $orderedQuantity - $receivedQuantity);
                $remaining = $remainingQuantity > 0
                    ? '<p class="mt-1 text-xs font-semibold text-amber-600">'.e((string) $remainingQuantity).' restant(s)</p>'
                    : '';

                return $this->purchaseStatusBadge($purchase->status).$remaining;
            })
            ->editColumn('total_amount', fn (Purchase $purchase): string => '<span class="font-semibold">'.$this->money($purchase->total_amount).'</span>')
            ->addColumn('action', fn (Purchase $purchase): string => $this->purchaseActionMenu($purchase))
            ->addColumn('row_url', fn (Purchase $purchase): string => route('module', ['module' => 'purchases', 'section' => 'list', 'detail_purchase' => $purchase->id]))
            ->rawColumns(['number_display', 'date_display', 'supplier_display', 'reference_display', 'created_by_display', 'items_display', 'receipt_display', 'total_amount', 'action', 'row_url'])
            ->toJson();
    }

    public function storeContact(Request $request): RedirectResponse|JsonResponse
    {
        $tenant = $this->tenant();
        $data = $this->validateContact($request, $tenant);
        $contact = Contact::create($this->contactPayload($request, $tenant, $data));

        if ($request->expectsJson()) {
            return response()->json([
                'id' => $contact->id,
                'label' => trim($contact->name.($contact->phone ? ' · '.$contact->phone : '')),
            ]);
        }

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

    public function importContacts(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $data = $request->validate([
            'contact_file' => ['required', 'file', 'max:20480'],
            'kind' => ['required', 'in:client,supplier'],
        ]);

        $rows = $this->rowsFromUpload($request->file('contact_file'));

        return $this->importContactRows($tenant, $rows, $data['kind']);
    }

    public function contactImportExample(string $kind)
    {
        abort_unless(in_array($kind, ['client', 'supplier'], true), 404);

        $example = $this->contactImportExampleRows($kind);
        $path = $this->buildSimpleXlsx($example['title'], $example['headers'], $example['rows']);

        return response()
            ->download($path, $example['filename'], [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend(true);
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
                    ((bool) $item->is_enabled && $item->status !== 'archived' ? 'Activé' : 'Désactivé').' / '.($item->type === 'service' ? 'Service' : $this->statusLabel($item->status)).' / '.((bool) $item->checkout_visible && (bool) $item->is_enabled && $item->status === 'active' ? 'Visible caisse' : 'Caché caisse'),
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
        $quickSearch = trim((string) $request->query('q'));
        $tableSearch = trim((string) data_get($request->input('search', []), 'value', ''));
        $hasSearch = $quickSearch !== '' || $tableSearch !== '';
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
            ->select('items.*')
            ->selectRaw('coalesce(catalog_categories.name, ?) as category_sort', ['Sans catégorie'])
            ->selectRaw('coalesce(catalog_brands.name, ?) as brand_sort', [''])
            ->selectRaw('coalesce(catalog_units.name, ?) as unit_sort', [''])
            ->selectRaw('coalesce(catalog_taxes.name, ?) as tax_sort', [''])
            ->leftJoin('categories as catalog_categories', 'catalog_categories.id', '=', 'items.category_id')
            ->leftJoin('brands as catalog_brands', 'catalog_brands.id', '=', 'items.brand_id')
            ->leftJoin('units as catalog_units', 'catalog_units.id', '=', 'items.unit_id')
            ->leftJoin('taxes as catalog_taxes', 'catalog_taxes.id', '=', 'items.tax_id')
            ->where('items.tenant_id', $tenant->id)
            ->with(['category', 'brand', 'unit', 'tax', 'variants'])
            ->when($quickSearch !== '', fn (Builder $builder) => $builder->where(function (Builder $builder) use ($quickSearch): void {
                $builder->where('items.title', 'like', "%{$quickSearch}%")
                    ->orWhere('items.item_code', 'like', "%{$quickSearch}%")
                    ->orWhere('items.sku', 'like', "%{$quickSearch}%")
                    ->orWhere('items.isbn', 'like', "%{$quickSearch}%")
                    ->orWhere('items.barcode', 'like', "%{$quickSearch}%")
                    ->orWhere('items.custom_barcode1', 'like', "%{$quickSearch}%")
                    ->orWhere('items.author', 'like', "%{$quickSearch}%")
                    ->orWhere('items.editor', 'like', "%{$quickSearch}%")
                    ->orWhere('items.description', 'like', "%{$quickSearch}%")
                    ->orWhere('catalog_categories.name', 'like', "%{$quickSearch}%")
                    ->orWhere('catalog_brands.name', 'like', "%{$quickSearch}%")
                    ->orWhere('catalog_units.name', 'like', "%{$quickSearch}%")
                    ->orWhere('catalog_taxes.name', 'like', "%{$quickSearch}%");
            }))
            ->when($status !== 'all', fn (Builder $builder) => $builder->where('items.status', $status))
            ->when($category === 'uncategorized', fn (Builder $builder) => $builder->whereNull('items.category_id'))
            ->when($category !== 'all' && $category !== 'uncategorized', fn (Builder $builder) => $builder->where('items.category_id', $category))
            ->when($brand !== 'all', fn (Builder $builder) => $builder->where('items.brand_id', $brand))
            ->when($unit !== 'all', fn (Builder $builder) => $builder->where('items.unit_id', $unit))
            ->when($tax !== 'all', fn (Builder $builder) => $builder->where('items.tax_id', $tax))
            ->when($stock === 'low', fn (Builder $builder) => $builder->whereColumn('items.stock_quantity', '<=', 'items.min_stock_threshold'))
            ->when($stock === 'out', fn (Builder $builder) => $builder->where('items.stock_quantity', '<=', 0))
            ->when(is_numeric($minPrice), fn (Builder $builder) => $builder->where('items.sale_price', '>=', (float) $minPrice))
            ->when(is_numeric($maxPrice), fn (Builder $builder) => $builder->where('items.sale_price', '<=', (float) $maxPrice))
            ->when(in_array($panel, ['services', 'ajouter-service'], true), fn (Builder $builder) => $builder->where('items.type', 'service'))
            ->when(! in_array($panel, ['services', 'ajouter-service', 'all'], true) && $type !== 'all', fn (Builder $builder) => $builder->where('items.type', $type))
            ->when(! in_array($panel, ['services', 'ajouter-service', 'all'], true) && $type === 'all' && ! $hasSearch, fn (Builder $builder) => $builder->where('items.type', '!=', 'service'));
    }

    public function storeItem(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $data = $this->validatedItem($request);
        $data['tenant_id'] = $tenant->id;
        $data['status'] = ($data['status'] ?? 'active') !== 'archived' && $data['stock_quantity'] <= 0 && ($data['type'] ?? 'book') !== 'service' ? 'out_of_stock' : ($data['status'] ?? 'active');

        $item = Item::create($data);

        if ($item->type !== 'service' && (int) $item->stock_quantity !== 0) {
            $inventoryService = app(\App\Services\Inventory\InventoryService::class);
            $locationId = $inventoryService->locationIdFromName($tenant->id, null);
            $inventoryService->move(new \App\Services\Inventory\MovementDTO(
                tenantId: $tenant->id,
                itemId: $item->id,
                variantId: null,
                locationId: $locationId,
                type: \App\Services\Inventory\InventoryMovementType::OPENING_STOCK,
                quantityChanged: (int) $item->stock_quantity,
                referenceType: Item::class,
                referenceId: $item->id,
                referenceNumber: $item->item_code,
                note: 'Stock initial article '.$item->item_code,
            ));
        }

        return back()->with('status', 'Article ajouté au catalogue.');
    }

    public function updateItem(Request $request, Item $item): RedirectResponse
    {
        $this->authorizeTenantItem($item);
        $tenant = $this->tenant();
        $beforeStock = (int) $item->stock_quantity;
        $beforeType = $item->type;
        $data = $this->validatedItem($request, $item);
        $data['status'] = ($data['status'] ?? $item->status) !== 'archived' && $data['stock_quantity'] <= 0 && ($data['type'] ?? $item->type) !== 'service' ? 'out_of_stock' : ($data['status'] ?? $item->status);

        $item->update($data);
        $item->refresh();

        if ($beforeType !== 'service' && $item->type !== 'service' && $beforeStock !== (int) $item->stock_quantity) {
            $inventoryService = app(\App\Services\Inventory\InventoryService::class);
            $locationId = $inventoryService->locationIdFromName($tenant->id, null);
            $inventoryService->move(new \App\Services\Inventory\MovementDTO(
                tenantId: $tenant->id,
                itemId: $item->id,
                variantId: null,
                locationId: $locationId,
                type: \App\Services\Inventory\InventoryMovementType::ITEM_UPDATE,
                quantityChanged: (int) $item->stock_quantity - $beforeStock,
                referenceType: Item::class,
                referenceId: $item->id,
                referenceNumber: $item->item_code,
                note: 'Modification fiche article '.$item->item_code,
            ));
        }

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
            'min_stock_threshold' => ['nullable', 'integer', 'min:0'],
        ]);

        abort_unless(Item::where('tenant_id', $tenant->id)->where('id', $data['item_id'])->exists(), 403);

        ItemVariant::create([
            'item_id' => $data['item_id'],
            'tenant_id' => $tenant->id,
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
            'min_stock_threshold' => (int) ($data['min_stock_threshold'] ?? 0),
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
            $type = $this->importedItemType($row, $data['kind']);
            $stock = $type === 'service' ? 9999 : (int) $this->decimalValue($this->rowValue($row, ['stock', 'stock_quantity', 'opening_stock', 'stock_ouverture']));
            $defaultMinStock = (int) data_get($tenant->settings, 'pos.default_min_stock_threshold', 3);

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
                'type' => $type,
                'is_enabled' => true,
                'checkout_visible' => true,
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
                'tags' => $this->normalizeTagsInput($this->rowValue($row, ['tags', 'tag', 'etiquettes', 'mots_cles', 'mots-cles'])),
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
                'min_stock_threshold' => (int) ($this->rowValue($row, ['min_stock_threshold', 'seuil_stock', 'alert_qty', 'quantite_d_alerte']) ?? $defaultMinStock),
                'location' => $this->rowValue($row, ['location', 'emplacement']),
            ];

            if ($barcode || $isbn) {
                $match = ['tenant_id' => $tenant->id];
                $barcode ? $match['barcode'] = $barcode : $match['isbn'] = $isbn;
                $existing = Item::where($match)->first();
                $beforeStock = $existing ? (int) $existing->stock_quantity : 0;
                $model = Item::updateOrCreate($match, $payload);
                $model->wasRecentlyCreated ? $created++ : $updated++;
            } else {
                $model = Item::create($payload);
                $beforeStock = 0;
                $created++;
            }

            if ($model->type !== 'service' && (int) $model->stock_quantity !== $beforeStock) {
                $this->recordStockMovement(
                    $tenant,
                    $model,
                    $model->wasRecentlyCreated ? 'import_opening_stock' : 'import_stock_update',
                    (int) $model->stock_quantity - $beforeStock,
                    Item::class,
                    $model->id,
                    ($model->wasRecentlyCreated ? 'Import stock initial ' : 'Import mise à jour stock ').($model->item_code ?? $model->barcode ?? $model->title)
                );
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
        $brand = $request->query('brand');
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
            ->when($brand, fn (Builder $builder) => $builder->where('brand_id', $brand))
            ->when(in_array($type, ['book', 'supply', 'service'], true), fn (Builder $builder) => $builder->where('type', $type))
            ->orderBy('title')
            ->take(160)
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
            'brands' => Brand::where('tenant_id', $tenant->id)->orderBy('name')->get(),
            'template' => $template,
            'selectedIds' => $ids,
            'quantities' => $quantities,
            'defaultCopies' => $defaultCopies,
            'query' => $query,
            'categoryFilter' => $category,
            'brandFilter' => $brand,
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
            'business_mode' => ['nullable', Rule::in(array_keys(BusinessMode::all()))],
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
            'store_logo_file' => ['nullable', 'image', 'max:2048'],
            'remove_store_logo' => ['nullable', 'boolean'],
            'timezone' => ['required', 'string', 'max:80', Rule::in(TenantClock::options())],
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
            'app_icon' => ['nullable', 'image', 'max:2048'],
            'remove_app_icon' => ['nullable', 'boolean'],
        ]);

        foreach (['show_signature', 'round_off', 'mrp_column', 'change_return', 'previous_balance_bit', 't_and_c_status', 't_and_c_status_pos', 'toggle_header_footer'] as $boolean) {
            $data[$boolean] = $request->boolean($boolean);
        }

        $data['business_mode'] = BusinessMode::get($data['business_mode'] ?? data_get($tenant->settings, 'company_profile.business_mode'))['key'];

        if ($request->boolean('remove_app_icon')) {
            $this->deleteAppIconFiles();
            $data['app_icon'] = null;
        } elseif ($request->hasFile('app_icon')) {
            $this->processAppIconUpload($request->file('app_icon'));
            $data['app_icon'] = 'custom';
        } else {
            $data['app_icon'] = $tenant->settings['company_profile']['app_icon'] ?? null;
        }

        if ($request->boolean('remove_store_logo')) {
            $old = $tenant->settings['company_profile']['store_logo'] ?? '';
            if ($old && !Str::startsWith($old, ['http://', 'https://'])) {
                $oldPath = public_path($old);
                if (is_file($oldPath)) @unlink($oldPath);
            }
            $data['store_logo'] = '';
        } elseif ($request->hasFile('store_logo_file')) {
            $file = $request->file('store_logo_file');
            $filename = 'logo-'.time().'.'.$file->getClientOriginalExtension();
            $logosDir = public_path('logos');
            if (!is_dir($logosDir)) {
                mkdir($logosDir, 0755, true);
            }
            $file->move($logosDir, $filename);
            $data['store_logo'] = 'logos/'.$filename;
        } else {
            $data['store_logo'] = $tenant->settings['company_profile']['store_logo'] ?? '';
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

    public function manifest(): \Illuminate\Http\JsonResponse
    {
        $tenant = TenantContext::resolve(request());
        $theme = data_get($tenant?->settings, 'theme.primary', '#3157D5');
        $businessMode = BusinessMode::current($tenant);
        $locale = $tenant?->locale ?? 'fr';
        $dir = str_starts_with($locale, 'ar') ? 'rtl' : 'ltr';

        return response()->json([
            'name' => $tenant?->name ?? 'LibrairePro',
            'short_name' => $tenant?->name ?? 'LibrairePro',
            'description' => 'Caisse, stock, ventes et achats pour '.$businessMode['short_label'],
            'start_url' => '/',
            'display' => 'standalone',
            'background_color' => '#F4F7FB',
            'theme_color' => $theme,
            'orientation' => 'any',
            'scope' => '/',
            'icons' => [
                [
                    'src' => route('app.icon', 192),
                    'sizes' => '192x192',
                    'type' => 'image/png',
                ],
                [
                    'src' => route('app.icon', 512),
                    'sizes' => '512x512',
                    'type' => 'image/png',
                ],
                [
                    'src' => route('app.icon', 192),
                    'sizes' => '192x192',
                    'type' => 'image/png',
                    'purpose' => 'maskable',
                ],
            ],
            'categories' => ['business', 'finance'],
            'lang' => $locale === 'ar' ? 'ar' : ($locale === 'en_US' ? 'en' : 'fr'),
            'dir' => $dir,
        ]);
    }

    public function appIcon(string $size): \Symfony\Component\HttpFoundation\BinaryFileResponse|\Illuminate\Http\Response
    {
        $path = public_path("icons/icon-{$size}x{$size}.png");

        if (!file_exists($path)) {
            $this->ensureDefaultAppIconGenerated($size);
        }

        if (file_exists($path)) {
            return response()->file($path, ['Content-Type' => 'image/png']);
        }

        return response('', 404);
    }

    private function ensureDefaultAppIconGenerated(int $size): void
    {
        $iconsDir = public_path('icons');
        if (!is_dir($iconsDir)) {
            mkdir($iconsDir, 0755, true);
        }

        $tenant = TenantContext::resolve(request());
        $primary = data_get($tenant?->settings, 'theme.primary', '#3157D5');
        $name = $tenant?->name ?? 'LP';
        $initials = $this->tenantInitials($name);

        $this->generateAppIconPng($iconsDir."/icon-{$size}x{$size}.png", $size, $primary, $initials);
    }

    private function tenantInitials(string $name): string
    {
        $words = preg_split('/\s+/', trim($name));
        $initials = '';
        foreach (array_slice($words, 0, 2) as $word) {
            $initials .= mb_strtoupper(mb_substr($word, 0, 1));
        }
        return $initials ?: 'LP';
    }

    private function generateAppIconPng(string $destination, int $size, string $color, string $text): void
    {
        $image = imagecreatetruecolor($size, $size);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        // Parse color
        $rgb = sscanf(ltrim($color, '#'), '%2x%2x%2x');
        $r = (int) ($rgb[0] ?? 49);
        $g = (int) ($rgb[1] ?? 87);
        $b = (int) ($rgb[2] ?? 213);

        // Transparent background
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefill($image, 0, 0, $transparent);

        // Rounded rectangle background
        $bgColor = imagecolorallocate($image, $r, $g, $b);
        $radius = (int) ($size * 0.22);
        $this->imageRoundedRectangle($image, 0, 0, $size - 1, $size - 1, $radius, $bgColor);

        // Text color (white)
        $textColor = imagecolorallocate($image, 255, 255, 255);

        // Font size proportional to icon size
        $fontSize = (int) ($size * 0.35);
        $text = mb_strtoupper($text);
        $fontPath = public_path('fonts/Inter-Bold.ttf');
        if (!file_exists($fontPath)) {
            $fontPath = '/System/Library/Fonts/Helvetica.ttc';
            if (!file_exists($fontPath)) {
                $fontPath = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
            }
        }
        $bbox = file_exists($fontPath) ? imagettfbbox($fontSize, 0, $fontPath, $text) : false;
        if ($bbox === false) {
            // Fallback to built-in font if TTF not available
            $fontSize = max(1, (int) ($size * 0.15));
            $textWidth = imagefontwidth($fontSize) * strlen($text);
            $textHeight = imagefontheight($fontSize);
            $x = (int) (($size - $textWidth) / 2);
            $y = (int) (($size - $textHeight) / 2 + $textHeight * 0.8);
            imagestring($image, $fontSize, $x, $y, $text, $textColor);
        } else {
            $textWidth = $bbox[2] - $bbox[0];
            $textHeight = $bbox[1] - $bbox[7];
            $x = (int) (($size - $textWidth) / 2);
            $y = (int) (($size + $textHeight) / 2 - $bbox[1]);
            imagettftext($image, $fontSize, 0, $x, $y, $textColor, $fontPath, $text);
        }

        imagepng($image, $destination, 9);
        imagedestroy($image);
    }

    private function imageRoundedRectangle(\GdImage $image, int $x1, int $y1, int $x2, int $y2, int $radius, int $color): void
    {
        imagefilledrectangle($image, $x1 + $radius, $y1, $x2 - $radius, $y2, $color);
        imagefilledrectangle($image, $x1, $y1 + $radius, $x2, $y2 - $radius, $color);
        imagefilledellipse($image, $x1 + $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($image, $x2 - $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($image, $x1 + $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($image, $x2 - $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
    }

    public function updatePosSettings(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $storeKeys = collect($this->storeCatalog($tenant))->pluck('key')->all();
        $data = $request->validate([
            'inventory_cycle_days' => ['nullable', 'integer', 'in:7,15,30,90'],
            'default_min_stock_threshold' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'online_pickup_store' => ['nullable', Rule::in($storeKeys)],
        ]);
        $settings = $tenant->settings ?? [];
        $settings['pos'] = array_merge($settings['pos'] ?? [], [
            'editable_price' => $request->boolean('editable_price'),
            'allow_sale_edit' => $request->boolean('allow_sale_edit', true),
            'allow_oversell' => $request->boolean('allow_oversell'),
            'show_out_of_stock' => $request->boolean('show_out_of_stock'),
            'show_cash_drawer_navbar' => $request->boolean('show_cash_drawer_navbar'),
            'require_adjustment_reason' => $request->boolean('require_adjustment_reason'),
            'update_cost_on_purchase' => $request->boolean('update_cost_on_purchase'),
            'low_stock_dashboard' => $request->boolean('low_stock_dashboard'),
            'auto_reorder_draft' => $request->boolean('auto_reorder_draft'),
            'inventory_cycle_days' => (int) ($data['inventory_cycle_days'] ?? 30),
            'default_min_stock_threshold' => (int) ($data['default_min_stock_threshold'] ?? 3),
        ]);
        $settings['online_store'] = array_merge($settings['online_store'] ?? [], [
            'enabled' => $request->boolean('online_store_enabled', true),
            'pickup_store' => $data['online_pickup_store'] ?? data_get($settings, 'current_store'),
        ]);
        $tenant->update(['settings' => $settings]);

        return back()->with('status', 'Paramètres caisse & stock mis à jour.');
    }

    public function updateModuleSettings(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $definitions = AppModules::all();
        $validKeys = array_keys($definitions);

        $data = $request->validate([
            'enabled' => ['nullable', 'array'],
            'enabled.*' => ['string', Rule::in($validKeys)],
            'order' => ['nullable', 'string', 'max:1000'],
        ]);

        $order = collect(explode(',', (string) ($data['order'] ?? '')))
            ->map(fn ($key) => trim($key))
            ->filter(fn ($key) => in_array($key, $validKeys, true))
            ->unique()
            ->values();
        $order = AppModules::normalizeOrder($order->merge(collect($validKeys)->diff($order))->values()->all());

        $selected = collect($data['enabled'] ?? [])->filter(fn ($key) => in_array($key, $validKeys, true))->values();
        $enabled = collect($definitions)->mapWithKeys(function (array $module, string $key) use ($selected): array {
            return [$key => (bool) ($module['locked'] ?? false) || $selected->contains($key)];
        })->all();

        $settings = $tenant->settings ?? [];
        $settings['modules'] = [
            'enabled' => $enabled,
            'order' => $order,
        ];

        $tenant->update(['settings' => $settings]);

        return redirect()
            ->route('module', ['module' => 'settings', 'section' => 'modules'])
            ->with('status', 'Modules et ordre du menu mis à jour.');
    }

    public function runDemoMaintenance(Request $request, string $action): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless($this->currentUserIsOwner($tenant), 403);

        $actions = array_keys($this->demoMaintenanceActions());
        $data = $request->validate([
            'confirmation' => ['required', 'string', 'in:DEMO'],
            'hard_delete'  => ['nullable', 'boolean'],
        ]);
        abort_unless(in_array($action, $actions, true), 404);

        $hardDelete = $request->boolean('hard_delete');
        $deleted = DB::transaction(fn (): array => $this->executeDemoMaintenanceAction($tenant, $action, $hardDelete));

        AuditLog::create([
            'tenant_id' => $tenant->id,
            'user_id' => auth()->id(),
            'action' => 'settings.demo_maintenance.'.$action,
            'friendly_action' => 'Nettoyage données démo'.($hardDelete ? ' (hard delete)' : ''),
            'subject_type' => Tenant::class,
            'subject_id' => $tenant->id,
            'subject_name_snapshot' => $tenant->name,
            'subject_reference_snapshot' => $action,
            'properties' => [
                'confirmation' => $data['confirmation'],
                'deleted' => $deleted,
            ],
        ]);

        $total = array_sum(array_map('intval', $deleted));

        return redirect()
            ->route('module', ['module' => 'settings', 'section' => 'demo-data'])
            ->with('status', 'Nettoyage démo exécuté: '.$total.' ligne(s) supprimée(s) ou réinitialisée(s).');
    }

    public function updateVirtualDeviceSettings(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $enabled = $request->boolean('virtual_devices_enabled');
        $settings = $tenant->settings ?? [];
        $settings['features'] = array_merge($settings['features'] ?? [], [
            'virtual_devices' => $enabled,
        ]);

        $tenant->update(['settings' => $settings]);

        if (! $enabled) {
            VirtualDeviceSession::where('tenant_id', $tenant->id)
                ->whereNull('disconnected_at')
                ->update([
                    'disconnected_at' => now(),
                    'disconnect_reason' => 'module_disabled',
                    'updated_at' => now(),
                ]);

            session()->forget('virtual_device_session_id');
        }

        return redirect()
            ->route('module', ['module' => 'settings', 'section' => 'hardware'])
            ->with('status', $enabled ? 'Module appareils virtuels activé.' : 'Module appareils virtuels désactivé.');
    }

    public function updateDocumentSettings(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $data = $request->validate([
            'sale_title' => ['required', 'string', 'max:120'],
            'invoice_title' => ['required', 'string', 'max:120'],
            'purchase_title' => ['required', 'string', 'max:120'],
            'primary_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'accent_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'header_text' => ['nullable', 'string', 'max:700'],
            'sale_note_template' => ['nullable', 'string', 'max:1200'],
            'invoice_note_template' => ['nullable', 'string', 'max:1200'],
            'purchase_note_template' => ['nullable', 'string', 'max:1200'],
            'footer_text' => ['nullable', 'string', 'max:1200'],
            'terms' => ['nullable', 'string', 'max:2000'],
            'show_logo' => ['nullable', 'boolean'],
            'show_signature' => ['nullable', 'boolean'],
            'show_bank_details' => ['nullable', 'boolean'],
        ]);

        $settings = $tenant->settings ?? [];
        $settings['documents'] = array_merge($this->documentSettings($tenant), [
            'sale_title' => $data['sale_title'],
            'invoice_title' => $data['invoice_title'],
            'purchase_title' => $data['purchase_title'],
            'primary_color' => $data['primary_color'],
            'accent_color' => $data['accent_color'],
            'header_text' => $data['header_text'] ?? '',
            'sale_note_template' => $data['sale_note_template'] ?? '',
            'invoice_note_template' => $data['invoice_note_template'] ?? '',
            'purchase_note_template' => $data['purchase_note_template'] ?? '',
            'footer_text' => $data['footer_text'] ?? '',
            'terms' => $data['terms'] ?? '',
            'show_logo' => $request->boolean('show_logo'),
            'show_signature' => $request->boolean('show_signature'),
            'show_bank_details' => $request->boolean('show_bank_details'),
        ]);

        $tenant->forceFill(['settings' => $settings])->save();

        return back()->with('status', 'Modèles PDF enregistrés.');
    }

    public function updateMessagingSettings(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $data = $request->validate([
            'default_channel' => ['required', 'in:sms,whatsapp,email'],
            'sender_name' => ['nullable', 'string', 'max:80'],
            'reply_to' => ['nullable', 'email', 'max:190'],
            'sms_provider' => ['nullable', 'string', 'max:80'],
            'sms_sender_id' => ['nullable', 'string', 'max:40'],
            'sms_api_key' => ['nullable', 'string', 'max:500'],
            'whatsapp_provider' => ['nullable', 'string', 'max:80'],
            'whatsapp_number' => ['nullable', 'string', 'max:40'],
            'whatsapp_token' => ['nullable', 'string', 'max:500'],
            'email_provider' => ['nullable', 'string', 'max:80'],
            'email_from' => ['nullable', 'email', 'max:190'],
            'test_mode' => ['nullable', 'boolean'],
            'log_messages' => ['nullable', 'boolean'],
        ]);

        $settings = $tenant->settings ?? [];
        $settings['messaging'] = array_merge($settings['messaging'] ?? [], [
            'default_channel' => $data['default_channel'],
            'sender_name' => $data['sender_name'] ?? null,
            'reply_to' => $data['reply_to'] ?? null,
            'sms_provider' => $data['sms_provider'] ?? 'local',
            'sms_sender_id' => $data['sms_sender_id'] ?? null,
            'sms_api_key' => $data['sms_api_key'] ?? null,
            'whatsapp_provider' => $data['whatsapp_provider'] ?? 'whatsapp_business',
            'whatsapp_number' => $data['whatsapp_number'] ?? null,
            'whatsapp_token' => $data['whatsapp_token'] ?? null,
            'email_provider' => $data['email_provider'] ?? 'smtp',
            'email_from' => $data['email_from'] ?? null,
            'test_mode' => $request->boolean('test_mode', true),
            'log_messages' => $request->boolean('log_messages', true),
        ]);
        $tenant->update(['settings' => $settings]);

        return back()->with('status', 'Configuration messagerie mise à jour.');
    }

    public function sendManualMessage(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $data = $request->validate([
            'channel' => ['required', 'in:sms,whatsapp,email'],
            'recipient_mode' => ['required', 'in:contact,manual'],
            'contact_id' => ['nullable', 'integer', Rule::exists('contacts', 'id')->where('tenant_id', $tenant->id)],
            'recipient' => ['nullable', 'string', 'max:190'],
            'subject' => ['nullable', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:1600'],
        ]);

        $contact = ! empty($data['contact_id']) ? Contact::where('tenant_id', $tenant->id)->find((int) $data['contact_id']) : null;
        $recipient = $data['recipient_mode'] === 'contact'
            ? $this->recipientForChannel($contact, $data['channel'])
            : trim((string) ($data['recipient'] ?? ''));

        if ($recipient === '') {
            return back()->withInput()->withErrors(['recipient' => 'Aucun destinataire valide pour ce canal.']);
        }

        $messaging = $this->messagingConfig($tenant);
        $status = $messaging['test_mode'] ? 'simulated' : 'queued';
        $entry = [
            'id' => (string) Str::uuid(),
            'channel' => $data['channel'],
            'recipient' => $recipient,
            'recipient_name' => $contact?->name,
            'contact_id' => $contact?->id,
            'subject' => $data['subject'] ?? null,
            'body' => $this->renderMessageBody($data['body'], $tenant, $contact),
            'status' => $status,
            'provider' => $messaging[$data['channel'].'_provider'] ?? $messaging['default_channel'],
            'created_by' => auth()->user()?->name,
            'created_at' => now()->toDateTimeString(),
        ];

        $settings = $tenant->settings ?? [];
        $outbox = collect($settings['messaging_outbox'] ?? [])->prepend($entry)->take(80)->values()->all();
        $settings['messaging_outbox'] = $outbox;
        $tenant->update(['settings' => $settings]);

        return back()->with('status', $status === 'simulated' ? 'Message simulé et ajouté au journal.' : 'Message ajouté à la file d’envoi.');
    }

    public function storeMessageTemplate(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $data = $this->validateMessageTemplate($request);
        $settings = $tenant->settings ?? [];
        $templates = collect($this->messageTemplates($tenant));
        $key = Str::slug($data['name']);
        abort_if($templates->contains(fn (array $template) => $template['key'] === $key), 422, 'Un modèle avec ce nom existe déjà.');
        $templates->push(array_merge($data, ['key' => $key]));
        $settings['message_templates'] = $templates->values()->all();
        $tenant->update(['settings' => $settings]);

        return back()->with('status', 'Modèle de message créé.');
    }

    public function updateMessageTemplate(Request $request, string $key): RedirectResponse
    {
        $tenant = $this->tenant();
        $data = $this->validateMessageTemplate($request);
        $templates = collect($this->messageTemplates($tenant));
        abort_unless($templates->contains(fn (array $template) => $template['key'] === $key), 404);
        $settings = $tenant->settings ?? [];
        $settings['message_templates'] = $templates
            ->map(fn (array $template) => $template['key'] === $key ? array_merge($template, $data) : $template)
            ->values()
            ->all();
        $tenant->update(['settings' => $settings]);

        return back()->with('status', 'Modèle de message mis à jour.');
    }

    public function destroyMessageTemplate(string $key): RedirectResponse
    {
        $tenant = $this->tenant();
        $templates = collect($this->messageTemplates($tenant));
        abort_unless($templates->contains(fn (array $template) => $template['key'] === $key), 404);
        $settings = $tenant->settings ?? [];
        $settings['message_templates'] = $templates->reject(fn (array $template) => $template['key'] === $key)->values()->all();
        $tenant->update(['settings' => $settings]);

        return back()->with('status', 'Modèle de message supprimé.');
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

        if (! empty($data['pin'])) {
            $this->ensurePinUnique($tenant, $data['pin']);
        }

        $user = \App\Models\User::create([
            'current_tenant_id' => $tenant->id,
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => Hash::make($data['password']),
            'pin_hash' => ! empty($data['pin']) ? Hash::make($data['pin']) : null,
            'avatar_color' => $data['avatar_color'] ?? '#4F46E5',
            'profile_photo_path' => $request->hasFile('profile_photo')
                ? $request->file('profile_photo')->store('users/profile-photos', 'public')
                : null,
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

        if (! empty($data['pin'])) {
            $this->ensurePinUnique($tenant, $data['pin'], $user);
            $payload['pin_hash'] = Hash::make($data['pin']);
        } elseif ($request->boolean('clear_pin')) {
            $payload['pin_hash'] = null;
        }

        if ($request->hasFile('profile_photo')) {
            if ($user->profile_photo_path) {
                Storage::disk('public')->delete($user->profile_photo_path);
            }

            $payload['profile_photo_path'] = $request->file('profile_photo')->store('users/profile-photos', 'public');
        } elseif ($request->boolean('remove_profile_photo') && $user->profile_photo_path) {
            Storage::disk('public')->delete($user->profile_photo_path);
            $payload['profile_photo_path'] = null;
        }

        $user->update($payload);
        $tenant->users()->updateExistingPivot($user->id, $this->tenantUserPayload($data));

        return redirect()
            ->route('module', ['module' => 'settings', 'section' => 'users'])
            ->with('status', 'Utilisateur '.$user->name.' mis à jour.');
    }

    public function updateUserPin(Request $request, \App\Models\User $user): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless($tenant->users()->whereKey($user->id)->exists(), 403);
        abort_unless($this->currentUserIsOwner($tenant), 403, 'Seul le propriétaire peut définir ou réinitialiser un PIN.');

        $data = $request->validate([
            'pin' => ['nullable', 'string', new FourDigitPin, 'confirmed'],
            'pin_confirmation' => ['nullable', 'required_with:pin', 'string', new FourDigitPin],
            'clear_pin' => ['nullable', 'boolean'],
        ]);

        if ($request->boolean('clear_pin')) {
            $user->update(['pin_hash' => null]);
            return redirect()
                ->route('module', ['module' => 'settings', 'section' => 'users'])
                ->with('status', 'PIN de '.$user->name.' supprimé.');
        }

        if (! empty($data['pin'])) {
            $this->ensurePinUnique($tenant, $data['pin'], $user);
            $user->update(['pin_hash' => Hash::make($data['pin'])]);
            return redirect()
                ->route('module', ['module' => 'settings', 'section' => 'users'])
                ->with('status', 'PIN de '.$user->name.' mis à jour.');
        }

        return back()->with('status', 'Aucun changement.');
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

    public function pos(Request $request): Response|RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless(AppModules::enabled($tenant, 'sales'), 404);
        $query = trim((string) $request->query('q'));
        $type = $request->query('type', 'all');
        $stock = $request->query('stock', 'available');
        $allowOversell = (bool) data_get($tenant->settings, 'pos.allow_oversell', false);
        $showOutOfStock = (bool) data_get($tenant->settings, 'pos.show_out_of_stock', false);
        $inventoryService = app(\App\Services\Inventory\InventoryService::class);
        $posLocationId = $inventoryService->locationIdFromName($tenant->id, null);
        $locationStockTable = (new ItemLocationStock())->getTable();
        $lastSaleId = $request->query('sale', session('last_pos_sale_id'));
        $resumeTicket = null;
        $sourceInvoice = null;
        $sourceOnlineOrder = null;
        $sourceCart = collect();
        $sourceContactId = null;
        if ($request->filled('ticket')) {
            $resumeTicket = PosTicket::where('tenant_id', $tenant->id)
                ->where('status', 'held')
                ->whereKey((int) $request->query('ticket'))
                ->first();
        }

        if ($request->filled('source_online_order')) {
            $sourceOnlineOrder = OnlineOrder::with(['contact', 'items', 'convertedSale'])
                ->where('tenant_id', $tenant->id)->whereKey((int) $request->query('source_online_order'))->firstOrFail();
            if ($sourceOnlineOrder->convertedSale) {
                return redirect()->route('pos', ['sale' => $sourceOnlineOrder->convertedSale->id]);
            }
            if (! $this->onlineOrderCanCreateSale($sourceOnlineOrder)) {
                return redirect()->route('module', ['module' => 'online-orders', 'section' => 'list', 'order' => $sourceOnlineOrder->id])
                    ->withErrors(['sale' => $this->onlineOrderSaleBlockReason($sourceOnlineOrder)]);
            }
            if ($sourceOnlineOrder->items->contains(fn (OnlineOrderItem $line) => ! $line->item_id)) {
                return redirect()->route('module', ['module' => 'online-orders', 'section' => 'list', 'order' => $sourceOnlineOrder->id])
                    ->withErrors(['sale' => 'Une ligne de la précommande n’est plus liée au catalogue.']);
            }
            $sourceCart = $sourceOnlineOrder->items->map(fn (OnlineOrderItem $line) => [
                'item_id' => $line->item_id, 'quantity' => (int) $line->quantity,
                'price' => (float) $line->unit_price, 'note' => $line->note,
            ]);
            $sourceContactId = $sourceOnlineOrder->contact_id;
        } elseif ($request->filled('source_invoice')) {
            $sourceInvoice = Invoice::with(['customer', 'items', 'sourceSale'])
                ->where('tenant_id', $tenant->id)->whereKey((int) $request->query('source_invoice'))->firstOrFail();
            if ($sourceInvoice->sourceSale) {
                return redirect()->route('pos', ['sale' => $sourceInvoice->sourceSale->id]);
            }
            if (! $this->invoiceCanCreateSale($sourceInvoice)) {
                return redirect()->route('module', ['module' => 'invoices', 'section' => 'invoices', 'invoice' => $sourceInvoice->id])
                    ->withErrors(['invoice' => $this->invoiceCreateSaleBlockReason($sourceInvoice)]);
            }
            if ($sourceInvoice->items->contains(fn ($line) => ! $line->item_id)) {
                return redirect()->route('module', ['module' => 'invoices', 'section' => 'invoices', 'invoice' => $sourceInvoice->id])
                    ->withErrors(['invoice' => 'Une ligne de la facture n’est plus liée au catalogue.']);
            }
            $sourceCart = $sourceInvoice->items->map(fn ($line) => [
                'item_id' => $line->item_id, 'quantity' => (int) $line->quantity,
                'price' => (float) $line->unit_price, 'note' => $line->description,
            ]);
            $sourceContactId = $sourceInvoice->customer_id;
        }

        $topSold = DB::table('sale_items')
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->where('sales.tenant_id', $tenant->id)
            ->whereNotNull('sale_items.item_id')
            ->selectRaw('sale_items.item_id, sum(sale_items.quantity) as sold_quantity')
            ->groupBy('sale_items.item_id')
            ->pluck('sold_quantity', 'item_id');

        $items = $tenant->items()
            ->select('items.*')
            ->leftJoin($locationStockTable.' as pos_stock', function ($join) use ($tenant, $posLocationId): void {
                $join->on('pos_stock.item_id', '=', 'items.id')
                    ->where('pos_stock.tenant_id', '=', $tenant->id)
                    ->where('pos_stock.location_id', '=', $posLocationId)
                    ->whereNull('pos_stock.variant_id');
            })
            ->selectRaw('coalesce(pos_stock.quantity, 0) as pos_stock_quantity')
            ->with(['category', 'brand', 'unit', 'tax'])
            ->when(
                $showOutOfStock,
                fn (Builder $builder) => $builder->whereIn('status', ['active', 'out_of_stock']),
                fn (Builder $builder) => $builder->where('status', 'active')
            )
            ->where('is_enabled', true)
            ->where('checkout_visible', true)
            // Search filter - searches across multiple fields
            ->when($query, fn (Builder $builder) => $builder->where(function (Builder $builder) use ($query): void {
                $builder->where('title', 'like', "%{$query}%")
                    ->orWhere('item_code', 'like', "%{$query}%")
                    ->orWhere('sku', 'like', "%{$query}%")
                    ->orWhere('isbn', 'like', "%{$query}%")
                    ->orWhere('barcode', 'like', "%{$query}%")
                    ->orWhere('custom_barcode1', 'like', "%{$query}%")
                    ->orWhere('author', 'like', "%{$query}%")
                    ->orWhere('editor', 'like', "%{$query}%")
                    ->orWhere('description', 'like', "%{$query}%")
                    ->orWhereHas('category', fn (Builder $categoryQuery) => $categoryQuery->where('name', 'like', "%{$query}%"))
                    ->orWhereHas('brand', fn (Builder $brandQuery) => $brandQuery->where('name', 'like', "%{$query}%"))
                    ->orWhereHas('unit', fn (Builder $unitQuery) => $unitQuery->where('name', 'like', "%{$query}%"));
            }))
            // Type filter - only apply when not searching
            ->when($type !== 'all' && !$query, fn (Builder $builder) => $builder->where('type', $type))
            // Stock filter - handle services specially
            ->when($stock === 'available' && ! $allowOversell && ! $showOutOfStock, fn (Builder $builder) => $builder->where(function (Builder $builder): void {
                $builder->where('items.type', 'service')->orWhereRaw('coalesce(pos_stock.quantity, 0) > 0');
            }))
            ->when($stock === 'low' && ! $allowOversell, fn (Builder $builder) => $builder->where('items.type', '!=', 'service')->whereRaw('coalesce(pos_stock.quantity, 0) <= items.min_stock_threshold'))
            ->when($stock === 'all', function (Builder $builder) {
                // Show everything when "all" is selected
            })
            // Order by: services first, then by stock status, then by title
            ->orderByRaw("case when items.type = 'service' then 0 when coalesce(pos_stock.quantity, 0) <= 0 then 2 when coalesce(pos_stock.quantity, 0) <= items.min_stock_threshold then 1 else 0 end")
            ->orderBy('items.title')
            // Fetch enough items to ensure services are included (frontend search filters from these)
            ->take($query !== '' ? 90 : 240)
            ->get()
            // Sort by popularity after database query
            ->sortByDesc(fn (Item $item) => (int) ($topSold[$item->id] ?? 0))
            ->values();

        $heldTickets = PosTicket::where('tenant_id', $tenant->id)->with('contact')->where('status', 'held')->latest('held_at')->take(8)->get();
        $heldTicketItems = Item::where('tenant_id', $tenant->id)
            ->whereIn('id', $heldTickets->flatMap(fn (PosTicket $ticket) => collect($ticket->cart)->pluck('item_id'))->unique()->values())
            ->get()
            ->keyBy('id');

        return response()->view('librairepro.pos', [
            'tenant' => $tenant,
            'active' => 'sales',
            'items' => $items,
            'clients' => Contact::where('tenant_id', $tenant->id)->where('kind', 'client')->orderBy('name')->take(80)->get(),
            'recentSales' => $tenant->sales()->with('contact')->latest('sold_at')->take(5)->get(),
            'lastSale' => $lastSaleId ? $tenant->sales()->with(['contact', 'items', 'user'])->whereKey($lastSaleId)->first() : null,
            'heldTickets' => $heldTickets,
            'heldTicketItems' => $heldTicketItems,
            'resumeTicket' => $resumeTicket,
            'sourceInvoice' => $sourceInvoice,
            'sourceOnlineOrder' => $sourceOnlineOrder,
            'sourceCart' => $sourceCart,
            'sourceContactId' => $sourceContactId,
            'nextSaleNumber' => $this->peekSaleNumber($tenant),
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
            'showOutOfStock' => $showOutOfStock,
            'posDiscountRules' => DiscountRule::where('tenant_id', $tenant->id)
                ->active()
                ->orderBy('name')
                ->get()
                ->map(fn (DiscountRule $rule) => $this->discountRulePayload($rule))
                ->values(),
        ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }

    public function posSearch(Request $request): JsonResponse
    {
        $tenant = $this->tenant();
        $query = trim((string) $request->query('q'));
        $type = $request->query('type', 'all');
        $stock = $request->query('stock', 'available');
        $category = $request->query('category', 'all');
        $brand = $request->query('brand', 'all');
        $unit = $request->query('unit', 'all');
        $allowOversell = (bool) data_get($tenant->settings, 'pos.allow_oversell', false);
        $showOutOfStock = (bool) data_get($tenant->settings, 'pos.show_out_of_stock', false);
        $inventoryService = app(\App\Services\Inventory\InventoryService::class);
        $posLocationId = $inventoryService->locationIdFromName($tenant->id, null);
        $locationStockTable = (new ItemLocationStock())->getTable();

        $topSold = DB::table('sale_items')
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->where('sales.tenant_id', $tenant->id)
            ->whereNotNull('sale_items.item_id')
            ->selectRaw('sale_items.item_id, sum(sale_items.quantity) as sold_quantity')
            ->groupBy('sale_items.item_id')
            ->pluck('sold_quantity', 'item_id');

        $items = $tenant->items()
            ->select('items.*')
            ->leftJoin($locationStockTable.' as pos_stock', function ($join) use ($tenant, $posLocationId): void {
                $join->on('pos_stock.item_id', '=', 'items.id')
                    ->where('pos_stock.tenant_id', '=', $tenant->id)
                    ->where('pos_stock.location_id', '=', $posLocationId)
                    ->whereNull('pos_stock.variant_id');
            })
            ->selectRaw('coalesce(pos_stock.quantity, 0) as pos_stock_quantity')
            ->with(['category', 'brand', 'unit'])
            ->when(
                $showOutOfStock,
                fn (Builder $builder) => $builder->whereIn('status', ['active', 'out_of_stock']),
                fn (Builder $builder) => $builder->where('status', 'active')
            )
            ->where('is_enabled', true)
            ->where('checkout_visible', true)
            ->when($query !== '', fn (Builder $builder) => $builder->where(function (Builder $builder) use ($query): void {
                $builder->where('title', 'like', "%{$query}%")
                    ->orWhere('item_code', 'like', "%{$query}%")
                    ->orWhere('sku', 'like', "%{$query}%")
                    ->orWhere('isbn', 'like', "%{$query}%")
                    ->orWhere('barcode', 'like', "%{$query}%")
                    ->orWhere('custom_barcode1', 'like', "%{$query}%")
                    ->orWhere('author', 'like', "%{$query}%")
                    ->orWhere('editor', 'like', "%{$query}%")
                    ->orWhere('description', 'like', "%{$query}%")
                    ->orWhereHas('category', fn (Builder $categoryQuery) => $categoryQuery->where('name', 'like', "%{$query}%"))
                    ->orWhereHas('brand', fn (Builder $brandQuery) => $brandQuery->where('name', 'like', "%{$query}%"))
                    ->orWhereHas('unit', fn (Builder $unitQuery) => $unitQuery->where('name', 'like', "%{$query}%"))
                    ->orWhereHas('variants', function (Builder $variantQuery) use ($query): void {
                        $variantQuery->where('barcode', $query)
                            ->orWhere('sku', $query)
                            ->orWhere('isbn', $query);
                    });
            }))
            ->when(in_array($type, ['book', 'supply', 'service'], true), fn (Builder $builder) => $builder->where('type', $type))
            ->when($category === 'uncategorized', fn (Builder $builder) => $builder->whereNull('category_id'))
            ->when($category !== 'all' && $category !== 'uncategorized', fn (Builder $builder) => $builder->where('category_id', $category))
            ->when($brand !== 'all', fn (Builder $builder) => $builder->where('brand_id', $brand))
            ->when($unit !== 'all', fn (Builder $builder) => $builder->where('unit_id', $unit))
            ->when($stock === 'available' && ! $allowOversell && ! $showOutOfStock, fn (Builder $builder) => $builder->where(function (Builder $builder): void {
                $builder->where('items.type', 'service')->orWhereRaw('coalesce(pos_stock.quantity, 0) > 0');
            }))
            ->when($stock === 'low' && ! $allowOversell, fn (Builder $builder) => $builder->where('items.type', '!=', 'service')->whereRaw('coalesce(pos_stock.quantity, 0) <= items.min_stock_threshold'))
            ->orderByRaw("case when items.type = 'service' then 0 when coalesce(pos_stock.quantity, 0) <= 0 then 2 when coalesce(pos_stock.quantity, 0) <= items.min_stock_threshold then 1 else 0 end")
            ->orderBy('items.title')
            ->limit($query === '' ? 240 : 80)
            ->get()
            ->sortByDesc(fn (Item $item) => (int) ($topSold[$item->id] ?? 0))
            ->values()
            ->map(fn (Item $item): array => $this->posItemPayload($item, $topSold, $allowOversell));

        return $this->noStoreJson([
            'items' => $items,
            'count' => $items->count(),
        ]);
    }

    public function previewCoupon(Request $request): JsonResponse
    {
        $tenant = $this->tenant();
        $data = $request->validate([
            'code' => ['required', 'string', 'max:80'],
            'subtotal' => ['required', 'numeric', 'min:0'],
            'contact_id' => ['nullable', 'integer', Rule::exists('contacts', 'id')->where('tenant_id', $tenant->id)],
        ]);

        $contact = ! empty($data['contact_id'])
            ? Contact::where('tenant_id', $tenant->id)->whereKey($data['contact_id'])->first()
            : null;
        $coupon = $this->couponDiscountForSubtotal($tenant, $data['code'], (float) $data['subtotal'], $contact, false);

        return response()->json([
            'valid' => $coupon['valid'],
            'message' => $coupon['message'],
            'coupon' => $coupon,
        ], $coupon['valid'] ? 200 : 422);
    }

    public function storeCoupon(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $data = $this->couponValidation($tenant, $request)->validate();
        $data['code'] = Str::upper(trim($data['code']));
        $data['tenant_id'] = $tenant->id;
        $data['is_active'] = $request->boolean('is_active', true);
        $data['metadata'] = [
            'created_from' => $request->input('source', 'finance'),
            'created_by' => auth()->id(),
        ];

        $coupon = Coupon::create($data);

        $couponSection = $coupon->contact_id ? 'customer-coupons' : 'coupons';
        return redirect()
            ->route('module', ['module' => 'finance', 'section' => $couponSection, 'detail_coupon' => $coupon->id])
            ->with('status', 'Coupon '.$coupon->code.' créé.');
    }

    public function updateCoupon(Request $request, Coupon $coupon): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless($coupon->tenant_id === $tenant->id, 404);
        $data = $this->couponValidation($tenant, $request, $coupon)->validate();
        $data['code'] = Str::upper(trim($data['code']));
        $data['is_active'] = $request->boolean('is_active');
        $coupon->update($data);

        return back()->with('status', 'Coupon '.$coupon->code.' mis à jour.');
    }

    public function destroyCoupon(Coupon $coupon): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless($coupon->tenant_id === $tenant->id, 404);

        if ($coupon->uses_count > 0) {
            $coupon->update(['is_active' => false]);

            return back()->with('status', 'Coupon désactivé car il possède déjà un historique.');
        }

        $coupon->delete();

        return back()->with('status', 'Coupon supprimé.');
    }

    public function storeDiscountRule(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $data = $this->discountRuleValidation($tenant, $request)->validate();
        $data = $this->preparedDiscountRuleData($tenant, $data, $request);
        $data['tenant_id'] = $tenant->id;
        $data['metadata'] = [
            'created_from' => 'finance',
            'created_by' => auth()->id(),
        ];

        $rule = DiscountRule::create($data);

        return redirect()
            ->route('module', ['module' => 'finance', 'section' => 'discounts', 'detail_discount' => $rule->id])
            ->with('status', 'Remise '.$rule->name.' créée.');
    }

    public function updateDiscountRule(Request $request, DiscountRule $discountRule): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless($discountRule->tenant_id === $tenant->id, 404);

        $data = $this->discountRuleValidation($tenant, $request, $discountRule)->validate();
        $discountRule->update($this->preparedDiscountRuleData($tenant, $data, $request));

        return back()->with('status', 'Remise '.$discountRule->name.' mise à jour.');
    }

    public function destroyDiscountRule(DiscountRule $discountRule): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless($discountRule->tenant_id === $tenant->id, 404);

        $discountRule->delete();

        return back()->with('status', 'Remise supprimée.');
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
            'discount_type' => ['nullable', 'in:fixed,percentage,Fixed,Percentage,percent'],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'discount_rule_id' => ['nullable', 'integer', Rule::exists('discount_rules', 'id')->where('tenant_id', $tenant->id)],
            'coupon_code' => ['nullable', 'string', 'max:80'],
            'cash_amount' => ['nullable', 'numeric', 'min:0'],
            'card_amount' => ['nullable', 'numeric', 'min:0'],
            'transfer_amount' => ['nullable', 'numeric', 'min:0'],
            'advance_amount' => ['nullable', 'numeric', 'min:0'],
            'receipt_channel' => ['nullable', 'in:print,email,whatsapp,none'],
            'note' => ['nullable', 'string', 'max:500'],
            'ticket_id' => ['nullable', 'integer', Rule::exists('pos_tickets', 'id')->where('tenant_id', $tenant->id)->where('status', 'held')],
            'source_invoice_id' => ['nullable', 'integer', Rule::exists('invoices', 'id')->where('tenant_id', $tenant->id)],
            'source_online_order_id' => ['nullable', 'integer', Rule::exists('online_orders', 'id')->where('tenant_id', $tenant->id)],
        ]);

        if (! empty($data['source_invoice_id']) && ! empty($data['source_online_order_id'])) {
            return back()->withErrors(['cart' => 'Une caisse ne peut provenir que d’un seul document source.'])->withInput();
        }

        $existingSourceSale = Sale::where('tenant_id', $tenant->id)
            ->when(! empty($data['source_invoice_id']), fn (Builder $query) => $query->where('source_invoice_id', $data['source_invoice_id']))
            ->when(! empty($data['source_online_order_id']), fn (Builder $query) => $query->where('source_online_order_id', $data['source_online_order_id']))
            ->when(empty($data['source_invoice_id']) && empty($data['source_online_order_id']), fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->first();
        if ($existingSourceSale) {
            return redirect()->route('pos', ['sale' => $existingSourceSale->id])
                ->with('status', 'La vente '.$existingSourceSale->number.' existe déjà.');
        }

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

        $discountInput = $this->normalizedDiscountInput($data);
        $payments = [
            'cash' => round((float) ($data['cash_amount'] ?? 0), 2),
            'card' => round((float) ($data['card_amount'] ?? 0), 2),
            'transfer' => round((float) ($data['transfer_amount'] ?? 0), 2),
            'advance' => round((float) ($data['advance_amount'] ?? 0), 2),
        ];
        $selectedPaymentMethods = collect($payments)
            ->filter(fn ($amount) => $amount > 0.001)
            ->keys()
            ->values()
            ->all();

            $idempotencyKey = $this->idempotencyKey($request);

        try {
            $sale = DB::transaction(function () use ($tenant, $data, $lineItems, $discountInput, $payments, $selectedPaymentMethods, $priceEditable, $allowOversell, $idempotencyKey) {
                $existing = $this->findByIdempotencyKey(Sale::class, $tenant->id, $idempotencyKey);
                if ($existing instanceof Sale) {
                    return $existing;
                }

                $sourceInvoice = ! empty($data['source_invoice_id'])
                    ? Invoice::where('tenant_id', $tenant->id)->whereKey($data['source_invoice_id'])->lockForUpdate()->firstOrFail()
                    : null;
                $sourceOnlineOrder = ! empty($data['source_online_order_id'])
                    ? OnlineOrder::where('tenant_id', $tenant->id)->whereKey($data['source_online_order_id'])->lockForUpdate()->firstOrFail()
                    : null;
                if ($sourceInvoice && ! $this->invoiceCanCreateSale($sourceInvoice)) {
                    throw new \RuntimeException($this->invoiceCreateSaleBlockReason($sourceInvoice));
                }
                if ($sourceOnlineOrder && ! $this->onlineOrderCanCreateSale($sourceOnlineOrder)) {
                    throw new \RuntimeException($this->onlineOrderSaleBlockReason($sourceOnlineOrder));
                }
                $existingSourceSale = Sale::where('tenant_id', $tenant->id)
                    ->when($sourceInvoice, fn (Builder $query) => $query->where('source_invoice_id', $sourceInvoice->id))
                    ->when($sourceOnlineOrder, fn (Builder $query) => $query->where('source_online_order_id', $sourceOnlineOrder->id))
                    ->when(! $sourceInvoice && ! $sourceOnlineOrder, fn (Builder $query) => $query->whereRaw('1 = 0'))
                    ->lockForUpdate()->first();
                if ($existingSourceSale) {
                    return $existingSourceSale;
                }

                $inventoryService = app(\App\Services\Inventory\InventoryService::class);
                $saleLocationId = $inventoryService->locationIdFromName($tenant->id, null);
                $saleLocationName = Location::where('tenant_id', $tenant->id)->whereKey($saleLocationId)->value('name') ?: 'magasin courant';
                $saleLocationName = Location::where('tenant_id', $tenant->id)->whereKey($saleLocationId)->value('name') ?: 'magasin courant';

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

                    if (! $allowOversell && $item->type !== 'service') {
                        $availableAtLocation = $inventoryService->quantity($tenant->id, $item->id, null, $saleLocationId);
                        if ($availableAtLocation < $line['quantity']) {
                            throw new \RuntimeException($this->saleStockUnavailableMessage($item, $availableAtLocation, $line['quantity'], $saleLocationName));
                        }
                    }

                    $catalogPrice = (float) $item->sale_price;
                    $unitPrice = $priceEditable && $line['unit_price'] !== null ? (float) $line['unit_price'] : $catalogPrice;
                    $lineTotal = round($unitPrice * $line['quantity'], 2);
                    $averageCost = $item->type !== 'service' ? $this->locationAverageCost($tenant->id, $item->id, null, $saleLocationId) : 0.0;
                    $subtotal += $lineTotal;
                    $saleLines[] = [
                        'item' => $item,
                        'quantity' => $line['quantity'],
                        'unit_price' => $unitPrice,
                        'catalog_price' => $catalogPrice,
                        'total_price' => $lineTotal,
                        'note' => $line['note'],
                        'price_overridden' => abs($unitPrice - $catalogPrice) > 0.001,
                        'average_cost' => $averageCost,
                        'cogs' => round($averageCost * $line['quantity'], 4),
                    ];
                }

                $totalCogs = collect($saleLines)->sum(fn (array $line) => $line['cogs']);

                $couponDetail = $this->couponDiscountForSubtotal($tenant, $data['coupon_code'] ?? null, $subtotal, $contact);
                $afterCoupon = max(0, $subtotal - $couponDetail['amount']);
                $ruleDetail = $this->discountRuleDiscountForCart($tenant, (int) ($data['discount_rule_id'] ?? 0), $lineItems, collect($items)->all(), $selectedPaymentMethods, $afterCoupon);
                $manualDiscountDetail = $ruleDetail['valid']
                    ? $this->discountForSubtotal($afterCoupon, 'fixed', 0)
                    : $this->discountForSubtotal($afterCoupon, $discountInput['type'], $discountInput['value']);
                $discount = min($subtotal, round($couponDetail['amount'] + $ruleDetail['amount'] + $manualDiscountDetail['amount'], 2));
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
                $saleReference = ! empty($data['ticket_id']) ? PosTicket::whereKey($data['ticket_id'])->value('number') : null;
                $soldAt = now();
                $changeAmount = max(0, round($paid - $total, 2));
                $cashChange = min($payments['cash'], $changeAmount);
                $cashDrawerIn = max(0, round($payments['cash'] - $cashChange, 2));
                $sale = Sale::create([
                    'tenant_id' => $tenant->id,
                    'contact_id' => $contact?->id,
                    'user_id' => auth()->id(),
                    'source_invoice_id' => $sourceInvoice?->id,
                    'source_online_order_id' => $sourceOnlineOrder?->id,
                    'number' => $saleNumber,
                    'status' => 'paid',
                    'payment_method' => $paymentMethod,
                    'subtotal_amount' => $subtotal,
                    'discount_amount' => $discount,
                    'tax_amount' => round($total * 0.2 / 1.2, 2),
                    'total_amount' => $total,
                    'sold_at' => $soldAt,
                    'idempotency_key' => $idempotencyKey,
                    'metadata' => [
                        ...$this->creationActorMetadata(),
                        'source_invoice_id' => $sourceInvoice?->id,
                        'source_invoice_number' => $sourceInvoice?->number,
                        'source_online_order_id' => $sourceOnlineOrder?->id,
                        'source_online_order_number' => $sourceOnlineOrder?->number,
                        'document_flow' => $sourceInvoice ? 'invoice_then_sale' : ($sourceOnlineOrder ? 'online_order_then_sale' : 'sale_first'),
                        'reference_number' => $saleReference,
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
                            'average_cost' => $line['average_cost'],
                            'cogs' => $line['cogs'],
                            'note' => $line['note'],
                        ])->values()->all(),
                        'cogs' => [
                            'total' => round($totalCogs, 4),
                            'currency' => 'MAD',
                        ],
                        'discount' => [
                            'amount' => $discount,
                            'manual' => $manualDiscountDetail,
                            'rule' => $ruleDetail,
                            'coupon' => $couponDetail,
                        ],
                        'receipt_channel' => $data['receipt_channel'] ?? 'print',
                        'system_note' => $this->saleSystemNote($tenant, $saleNumber, 'pos', $contact, count($saleLines), $total, $paymentMethod, 'paid', $soldAt, $saleReference),
                        'note' => $data['note'] ?? null,
                    ],
                ]);

                foreach ($saleLines as $line) {
                    $sale->items()->create([
                        'item_id'    => $line['item']->id,
                        'name'       => $line['item']->title,
                        'quantity'   => $line['quantity'],
                        'unit_price' => $line['unit_price'],
                        'total_price' => $line['total_price'],
                        'unit_cost'  => $line['average_cost'],
                        'total_cost' => $line['cogs'],
                    ]);

                    if ($line['item']->type !== 'service') {
                        $inventoryService->move(new \App\Services\Inventory\MovementDTO(
                            tenantId: $tenant->id,
                            itemId: $line['item']->id,
                            variantId: null,
                            locationId: $saleLocationId,
                            type: \App\Services\Inventory\InventoryMovementType::SALE,
                            quantityChanged: -$line['quantity'],
                            unitCost: $line['average_cost'] > 0 ? $line['average_cost'] : null,
                            allowNegative: $allowOversell,
                            referenceType: Sale::class,
                            referenceId: $sale->id,
                            referenceNumber: $sale->number,
                            note: 'Vente '.$sale->number,
                        ));

                        $line['item']->decrement('stock_quantity', $line['quantity']);
                        $line['item']->refresh();
                        if (! $allowOversell && $line['item']->stock_quantity <= 0) {
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

                if ($cashDrawerIn > 0.001 && $session = $this->openCashRegisterSession($tenant, true)) {
                    $movement = $this->recordCashRegisterMovement($tenant, $session, 'sale_cash', 'in', $cashDrawerIn, [
                        'sale_id' => $sale->id,
                        'reference' => $sale->number,
                        'payment_method' => 'cash',
                        'note' => 'Encaissement espèces vente '.$sale->number,
                        'metadata' => [
                            'cash_received' => $payments['cash'],
                            'cash_change' => $cashChange,
                            'paid_amount' => $paid,
                        ],
                    ]);
                    $metadata = $sale->metadata ?? [];
                    $metadata['cash_register'] = array_merge($metadata['cash_register'] ?? [], [
                        'session_id' => $session->id,
                        'session_number' => $session->number,
                        'movement_id' => $movement->id,
                        'movement_number' => $movement->number,
                    ]);
                    $sale->forceFill(['metadata' => $metadata])->save();
                }

                if ($couponDetail['coupon_id']) {
                    Coupon::where('tenant_id', $tenant->id)->whereKey($couponDetail['coupon_id'])->update([
                        'uses_count' => DB::raw('uses_count + 1'),
                        'used_amount' => DB::raw('used_amount + '.(float) $couponDetail['amount']),
                        'updated_at' => now(),
                    ]);
                    $sale->coupons()->syncWithoutDetaching([$couponDetail['coupon_id'] => [
                        'tenant_id' => $tenant->id,
                        'amount_applied' => (float) $couponDetail['amount'],
                    ]]);
                }

                if ($ruleDetail['valid'] && $ruleDetail['rule_id']) {
                    $sale->discountRules()->syncWithoutDetaching([$ruleDetail['rule_id'] => [
                        'tenant_id' => $tenant->id,
                        'amount_applied' => (float) $ruleDetail['amount'],
                    ]]);
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

                if ($sourceOnlineOrder) {
                    $sourceOnlineOrder->update([
                        'converted_sale_id' => $sale->id,
                        'converted_by' => auth()->id(),
                        'converted_at' => now(),
                        'status' => 'fulfilled',
                        'payment_status' => 'paid',
                    ]);
                }

                return $sale;
            });
        } catch (\App\Services\Inventory\InsufficientStockException $exception) {
            $item = Item::where('tenant_id', $tenant->id)->find($exception->itemId);
            $locationName = Location::where('tenant_id', $tenant->id)->whereKey($exception->locationId)->value('name') ?: 'magasin courant';

            return back()->withErrors([
                'cart' => $this->saleStockUnavailableMessage($item, $exception->available, $exception->requested, $locationName),
            ])->withInput();
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['cart' => $exception->getMessage()])->withInput();
        }

        return redirect()
            ->route('pos', ['sale' => $sale->id])
            ->with('status', 'Vente '.$sale->number.' encaissée.')
            ->with('last_pos_sale_id', $sale->id);
    }

    public function holdPosTicket(Request $request): RedirectResponse|JsonResponse
    {
        $tenant = $this->tenant();
        $data = $request->validate([
            'contact_id' => ['nullable', 'integer', Rule::exists('contacts', 'id')->where('tenant_id', $tenant->id)],
            'client_name' => ['nullable', 'string', 'max:160'],
            'client_phone' => ['nullable', 'string', 'max:60'],
            'cart' => ['required', 'json'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'discount_type' => ['nullable', 'in:fixed,percentage,Fixed,Percentage,percent'],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'coupon_code' => ['nullable', 'string', 'max:80'],
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

        $discountInput = $this->normalizedDiscountInput($data);

        try {
            $ticket = DB::transaction(function () use ($tenant, $data, $lineItems, $discountInput) {
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

                $contact = $contactId ? Contact::where('tenant_id', $tenant->id)->whereKey($contactId)->first() : null;
                $totals = $this->cartTotals($tenant, $lineItems, $discountInput, $data['coupon_code'] ?? null, $contact);
                $payload = [
                    'tenant_id' => $tenant->id,
                    'contact_id' => $contactId,
                    'user_id' => auth()->id(),
                    'cart' => $lineItems->values()->all(),
                    'subtotal_amount' => $totals['subtotal'],
                    'discount_amount' => $totals['discount'],
                    'discount_type' => $totals['discount_type'],
                    'discount_value' => $totals['discount_value'],
                    'coupon_code' => $totals['coupon']['code'] ?? null,
                    'coupon_amount' => $totals['coupon']['amount'] ?? 0,
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

        $message = 'Ticket '.$ticket->number.' mis en attente.';

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => $message,
                'ticket' => [
                    'id' => $ticket->id,
                    'number' => $ticket->number,
                    'total' => (float) $ticket->total_amount,
                    'total_formatted' => $this->money($ticket->total_amount),
                ],
            ]);
        }

        return redirect()
            ->route('pos')
            ->with('status', $message);
    }

    public function destroyPosTicket(PosTicket $ticket): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless($ticket->tenant_id === $tenant->id && $ticket->status === 'held', 404);
        $number = $ticket->number;
        $ticket->update(['status' => 'void']);

        return redirect()->route('pos')->with('status', 'Ticket '.$number.' annulé.');
    }

    public function storeSale(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $data = $request->validate([
            'contact_id' => ['nullable', 'integer', Rule::exists('contacts', 'id')->where('tenant_id', $tenant->id)->where('kind', 'client')],
            'client_name' => ['nullable', 'string', 'max:160'],
            'client_phone' => ['nullable', 'string', 'max:60'],
            'sold_at' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'reference_number' => ['nullable', 'string', 'max:120'],
            'source_invoice_id' => ['nullable', 'integer', Rule::exists('invoices', 'id')->where('tenant_id', $tenant->id)],
            'source_online_order_id' => ['nullable', 'integer', Rule::exists('online_orders', 'id')->where('tenant_id', $tenant->id)],
            'sale_status' => ['required', 'in:paid,partial,unpaid'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'other_charges' => ['nullable', 'numeric', 'min:0'],
            'cash_amount' => ['nullable', 'numeric', 'min:0'],
            'card_amount' => ['nullable', 'numeric', 'min:0'],
            'transfer_amount' => ['nullable', 'numeric', 'min:0'],
            'advance_amount' => ['nullable', 'numeric', 'min:0'],
            'delivery_address' => ['nullable', 'string', 'max:1000'],
            'delivery_note' => ['nullable', 'string', 'max:500'],
            'note' => ['nullable', 'string', 'max:700'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['nullable', 'integer', Rule::exists('items', 'id')->where('tenant_id', $tenant->id)],
            'items.*.quantity' => ['nullable', 'integer', 'min:1'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.description' => ['nullable', 'string', 'max:300'],
        ]);

        $lines = collect($data['items'])
            ->map(fn (array $line) => [
                'item_id' => (int) ($line['item_id'] ?? 0),
                'quantity' => max(0, (int) ($line['quantity'] ?? 0)),
                'unit_price' => isset($line['unit_price']) && $line['unit_price'] !== '' ? round(max(0, (float) $line['unit_price']), 2) : null,
                'discount_amount' => round(max(0, (float) ($line['discount_amount'] ?? 0)), 2),
                'tax_rate' => round(max(0, (float) ($line['tax_rate'] ?? 20)), 2),
                'description' => mb_substr(trim((string) ($line['description'] ?? '')), 0, 300),
            ])
            ->filter(fn (array $line) => $line['item_id'] > 0 && $line['quantity'] > 0)
            ->values();

        if ($lines->isEmpty()) {
            return back()->withErrors(['items' => 'Ajoutez au moins un article à la vente.'])->withInput();
        }

        $allowOversell = (bool) data_get($tenant->settings, 'pos.allow_oversell', false);
        $payments = [
            'cash' => round((float) ($data['cash_amount'] ?? 0), 2),
            'card' => round((float) ($data['card_amount'] ?? 0), 2),
            'transfer' => round((float) ($data['transfer_amount'] ?? 0), 2),
            'advance' => round((float) ($data['advance_amount'] ?? 0), 2),
        ];

        $idempotencyKey = $this->idempotencyKey($request);
        $sourceInvoiceId = (int) ($data['source_invoice_id'] ?? 0);
        $sourceOnlineOrderId = (int) ($data['source_online_order_id'] ?? 0);
        $sourceInvoiceSaleReused = false;
        $sourceOnlineOrderSaleReused = false;

        try {
            $sale = DB::transaction(function () use ($tenant, $data, $lines, $payments, $allowOversell, $idempotencyKey, $sourceInvoiceId, $sourceOnlineOrderId, &$sourceInvoiceSaleReused, &$sourceOnlineOrderSaleReused): Sale {
                $existing = $this->findByIdempotencyKey(Sale::class, $tenant->id, $idempotencyKey);
                if ($existing instanceof Sale) {
                    return $existing;
                }

                $sourceInvoice = null;
                $sourceOnlineOrder = null;
                if ($sourceInvoiceId > 0) {
                    $sourceInvoice = Invoice::where('tenant_id', $tenant->id)->whereKey($sourceInvoiceId)->lockForUpdate()->firstOrFail();
                    if (! $this->invoiceCanCreateSale($sourceInvoice)) {
                        throw new \RuntimeException($this->invoiceCreateSaleBlockReason($sourceInvoice));
                    }
                    $existingInvoiceSale = Sale::where('tenant_id', $tenant->id)
                        ->where('source_invoice_id', $sourceInvoice->id)
                        ->lockForUpdate()
                        ->first();
                    if ($existingInvoiceSale) {
                        $sourceInvoiceSaleReused = true;

                        return $existingInvoiceSale;
                    }
                }
                if ($sourceOnlineOrderId > 0) {
                    $sourceOnlineOrder = OnlineOrder::where('tenant_id', $tenant->id)
                        ->with('items')
                        ->whereKey($sourceOnlineOrderId)
                        ->lockForUpdate()
                        ->firstOrFail();
                    $existingOnlineOrderSale = Sale::where('tenant_id', $tenant->id)
                        ->where('source_online_order_id', $sourceOnlineOrder->id)
                        ->lockForUpdate()
                        ->first();
                    if ($existingOnlineOrderSale) {
                        $sourceOnlineOrderSaleReused = true;

                        return $existingOnlineOrderSale;
                    }
                    if (! $this->onlineOrderCanCreateSale($sourceOnlineOrder)) {
                        throw new \RuntimeException($this->onlineOrderSaleBlockReason($sourceOnlineOrder));
                    }
                }

                $inventoryService = app(\App\Services\Inventory\InventoryService::class);
                $saleLocationId = $inventoryService->locationIdFromName($tenant->id, null);

                $contact = null;
                if (! empty($data['contact_id'])) {
                    $contact = Contact::where('tenant_id', $tenant->id)->whereKey($data['contact_id'])->lockForUpdate()->firstOrFail();
                } elseif (filled($data['client_name'] ?? null)) {
                    $contact = Contact::create([
                        'tenant_id' => $tenant->id,
                        'kind' => 'client',
                        'name' => $data['client_name'],
                        'phone' => $data['client_phone'] ?? null,
                        'client_type' => 'individual',
                    ]);
                }

                if (($payments['advance'] ?? 0) > 0 && ! $contact) {
                    throw new \RuntimeException('Sélectionnez un client pour utiliser une avance.');
                }

                $items = Item::where('tenant_id', $tenant->id)
                    ->whereIn('id', $lines->pluck('item_id'))
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                $saleLines = [];
                $subtotal = 0.0;
                $lineDiscountTotal = 0.0;
                $taxAmount = 0.0;

                foreach ($lines as $line) {
                    $item = $items->get($line['item_id']);
                    if (! $item || $item->status !== 'active') {
                        throw new \RuntimeException('Un article de la vente est indisponible.');
                    }

                    if (! $allowOversell && $item->type !== 'service') {
                        $availableAtLocation = $inventoryService->quantity($tenant->id, $item->id, null, $saleLocationId);
                        if ($availableAtLocation < $line['quantity']) {
                            throw new \RuntimeException($this->saleStockUnavailableMessage($item, $availableAtLocation, $line['quantity'], $saleLocationName));
                        }
                    }

                    $unitPrice = $line['unit_price'] ?? (float) $item->sale_price;
                    $grossLine = round($unitPrice * $line['quantity'], 2);
                    $lineDiscount = min($line['discount_amount'], $grossLine);
                    $netLine = max(0, round($grossLine - $lineDiscount, 2));
                    $lineTax = $line['tax_rate'] > 0 ? round($netLine * $line['tax_rate'] / (100 + $line['tax_rate']), 2) : 0.0;
                    $averageCost = $item->type !== 'service' ? $this->locationAverageCost($tenant->id, $item->id, null, $saleLocationId) : 0.0;

                    $subtotal += $grossLine;
                    $lineDiscountTotal += $lineDiscount;
                    $taxAmount += $lineTax;
                    $saleLines[] = [
                        'item' => $item,
                        'quantity' => $line['quantity'],
                        'unit_price' => $unitPrice,
                        'gross_price' => $grossLine,
                        'discount_amount' => $lineDiscount,
                        'tax_rate' => $line['tax_rate'],
                        'tax_amount' => $lineTax,
                        'total_price' => $netLine,
                        'description' => $line['description'],
                        'average_cost' => $averageCost,
                        'cogs' => round($averageCost * $line['quantity'], 4),
                    ];
                }

                $globalDiscount = min(round((float) ($data['discount_amount'] ?? 0), 2), max(0, $subtotal - $lineDiscountTotal));
                $otherCharges = round((float) ($data['other_charges'] ?? 0), 2);
                $total = max(0, round($subtotal - $lineDiscountTotal - $globalDiscount + $otherCharges, 2));
                $totalCogs = collect($saleLines)->sum(fn (array $line) => $line['cogs']);
                $paid = min(round(array_sum($payments), 2), $total);

                if ($data['sale_status'] === 'unpaid') {
                    $payments = array_fill_keys(array_keys($payments), 0.0);
                    $paid = 0.0;
                }

                if ($contact && ($payments['advance'] ?? 0) > 0) {
                    if ((float) $contact->advance_balance < $payments['advance']) {
                        throw new \RuntimeException('Avance client insuffisante.');
                    }
                    $contact->decrement('advance_balance', min($payments['advance'], $paid));
                }

                $status = $paid <= 0.001 ? 'unpaid' : ($paid + 0.001 >= $total ? 'paid' : 'partial');
                if ($data['sale_status'] === 'unpaid') {
                    $status = 'unpaid';
                } elseif ($data['sale_status'] === 'paid' && $paid + 0.001 < $total) {
                    throw new \RuntimeException('Le montant payé doit couvrir le total pour une vente payée.');
                } elseif ($data['sale_status'] === 'partial' && $paid <= 0.001) {
                    throw new \RuntimeException('Ajoutez un paiement pour une vente partielle.');
                }

                $paymentMethod = collect($payments)->filter(fn ($amount) => $amount > 0.001)->keys()->join('+') ?: 'credit';
                $saleNumber = $this->nextSaleNumber($tenant);
                $soldAt = ! empty($data['sold_at']) ? Carbon::parse($data['sold_at']) : now();
                $sale = Sale::create([
                    'tenant_id' => $tenant->id,
                    'contact_id' => $contact?->id,
                    'user_id' => auth()->id(),
                    'source_invoice_id' => $sourceInvoice?->id,
                    'source_online_order_id' => $sourceOnlineOrder?->id,
                    'number' => $saleNumber,
                    'status' => $status,
                    'payment_method' => $paymentMethod,
                    'subtotal_amount' => round($subtotal, 2),
                    'discount_amount' => round($lineDiscountTotal + $globalDiscount, 2),
                    'tax_amount' => round($taxAmount, 2),
                    'total_amount' => $total,
                    'sold_at' => $soldAt,
                    'idempotency_key' => $idempotencyKey,
                    'metadata' => [
                        ...$this->creationActorMetadata(),
                        'reference_number' => $data['reference_number'] ?? null,
                        'source_invoice_id' => $sourceInvoice?->id,
                        'source_invoice_number' => $sourceInvoice?->number,
                        'source_online_order_id' => $sourceOnlineOrder?->id,
                        'source_online_order_number' => $sourceOnlineOrder?->number,
                        'document_flow' => $sourceInvoice ? 'invoice_then_sale' : ($sourceOnlineOrder ? 'online_order_then_sale' : 'sale_first'),
                        'document_origin' => $sourceInvoice ? 'commercial_invoice' : ($sourceOnlineOrder ? 'online_order' : 'manual_sale'),
                        'paid_amount' => $paid,
                        'due_date' => $data['due_date'] ?? null,
                        'source' => 'manual_sale',
                        'other_charges' => $otherCharges,
                        'global_discount_amount' => $globalDiscount,
                        'line_discount_amount' => round($lineDiscountTotal, 2),
                        'payments' => $payments,
                        'delivery_address' => $data['delivery_address'] ?? null,
                        'delivery_note' => $data['delivery_note'] ?? null,
                        'cogs' => [
                            'total' => round($totalCogs, 4),
                            'currency' => 'MAD',
                        ],
                        'system_note' => $this->saleSystemNote($tenant, $saleNumber, 'manual_sale', $contact, count($saleLines), $total, $paymentMethod, $status, $soldAt, $data['reference_number'] ?? null),
                        'note' => $data['note'] ?? null,
                    ],
                ]);

                foreach ($saleLines as $line) {
                    $sale->items()->create([
                        'item_id'    => $line['item']->id,
                        'name'       => $line['item']->title,
                        'quantity'   => $line['quantity'],
                        'unit_price' => $line['unit_price'],
                        'total_price' => $line['total_price'],
                        'unit_cost'  => $line['average_cost'],
                        'total_cost' => $line['cogs'],
                    ]);

                    if ($line['item']->type !== 'service') {
                        $reserveStock = in_array($status, ['unpaid', 'partial'], true)
                            && (bool) data_get($tenant->settings, 'pos.reserve_stock_for_unpaid_sales', false);

                        if ($reserveStock) {
                            $inventoryService->reserve(
                                tenantId: $tenant->id,
                                itemId: $line['item']->id,
                                variantId: null,
                                locationId: $saleLocationId,
                                quantity: $line['quantity'],
                                reason: 'Réservation vente '.$sale->number,
                                idempotencyKey: 'sale-reserve-'.$sale->id.'-item-'.$line['item']->id,
                                referenceType: Sale::class,
                                referenceId: $sale->id,
                            );
                        } else {
                            $inventoryService->move(new \App\Services\Inventory\MovementDTO(
                                tenantId: $tenant->id,
                                itemId: $line['item']->id,
                                variantId: null,
                                locationId: $saleLocationId,
                                type: \App\Services\Inventory\InventoryMovementType::SALE,
                                quantityChanged: -$line['quantity'],
                                unitCost: $line['average_cost'] > 0 ? $line['average_cost'] : null,
                                allowNegative: $allowOversell,
                                referenceType: Sale::class,
                                referenceId: $sale->id,
                                referenceNumber: $sale->number,
                                note: 'Vente manuelle '.$sale->number,
                            ));

                            $line['item']->decrement('stock_quantity', $line['quantity']);
                            $line['item']->refresh();
                            if (! $allowOversell && $line['item']->fresh()->stock_quantity <= 0) {
                                $line['item']->update(['status' => 'out_of_stock']);
                            }
                        }
                    }
                }

                $remainingPaymentToAllocate = $paid;
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
                        'paid_at' => $sale->sold_at,
                        'reference' => $sale->number,
                        'note' => 'Paiement vente manuelle',
                    ]);
                    $remainingPaymentToAllocate = max(0, round($remainingPaymentToAllocate - $allocatedAmount, 2));
                }

                if (! empty($data['delivery_address'])) {
                    DeliveryOrder::create([
                        'tenant_id' => $tenant->id,
                        'sale_id' => $sale->id,
                        'contact_id' => $sale->contact_id,
                        'user_id' => auth()->id(),
                        'number' => $this->nextDeliveryNumber($tenant),
                        'status' => 'pending',
                        'delivery_address' => $data['delivery_address'],
                        'note' => $data['delivery_note'] ?? null,
                    ]);
                }

                if ($sourceOnlineOrder) {
                    $orderMetadata = $sourceOnlineOrder->metadata ?? [];
                    $orderMetadata['status_history'] = collect($orderMetadata['status_history'] ?? [])
                        ->push([
                            'from' => $sourceOnlineOrder->status,
                            'to' => 'fulfilled',
                            'payment_status' => $status === 'paid' ? 'paid' : ($status === 'partial' ? 'deposit' : $sourceOnlineOrder->payment_status),
                            'user_id' => auth()->id(),
                            'user_name' => auth()->user()?->name,
                            'at' => now()->toIso8601String(),
                            'note' => 'Conversion en vente '.$sale->number,
                        ])
                        ->take(-30)
                        ->values()
                        ->all();
                    $orderMetadata['converted_sale_number'] = $sale->number;
                    $orderMetadata = array_merge($orderMetadata, $this->actorMetadata('updated'));

                    $sourceOnlineOrder->update([
                        'converted_sale_id' => $sale->id,
                        'converted_by' => auth()->id(),
                        'converted_at' => now(),
                        'status' => 'fulfilled',
                        'payment_status' => $status === 'paid' ? 'paid' : ($status === 'partial' ? 'deposit' : $sourceOnlineOrder->payment_status),
                        'metadata' => $orderMetadata,
                    ]);
                }

                return $sale;
            });
        } catch (\App\Services\Inventory\InsufficientStockException $exception) {
            $item = Item::where('tenant_id', $tenant->id)->find($exception->itemId);
            $locationName = Location::where('tenant_id', $tenant->id)->whereKey($exception->locationId)->value('name') ?: 'magasin courant';

            return back()->withErrors([
                'sale' => $this->saleStockUnavailableMessage($item, $exception->available, $exception->requested, $locationName),
            ])->withInput();
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['sale' => $exception->getMessage()])->withInput();
        }

        return redirect()
            ->route('module', ['module' => 'sales', 'section' => 'list', 'detail_sale' => $sale->id])
            ->with('status', $sourceInvoiceSaleReused
                ? 'Une vente existe déjà pour cette facture: '.$sale->number.'.'
                : ($sourceOnlineOrderSaleReused
                    ? 'Une vente existe déjà pour cette précommande: '.$sale->number.'.'
                    : 'Vente '.$sale->number.' enregistrée.'));
    }

    public function updateSale(Request $request, Sale $sale): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless($sale->tenant_id === $tenant->id, 404);
        if (! (bool) data_get($tenant->settings, 'pos.allow_sale_edit', true)) {
            return back()->withErrors(['sale_edit' => 'La modification des ventes est désactivée dans les paramètres magasin.'])->withInput();
        }

        $data = $request->validate([
            'contact_id' => ['nullable', 'integer', Rule::exists('contacts', 'id')->where('tenant_id', $tenant->id)->where('kind', 'client')],
            'reference_number' => ['nullable', 'string', 'max:120'],
            'due_date' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $paidAmount = $this->salePaidAmount($sale);
        $expectedStatus = $this->salePaymentStatus($sale, $paidAmount);

        $metadata = $sale->metadata ?? [];
        $metadata['reference_number'] = $data['reference_number'] ?? null;
        $metadata['due_date'] = $data['due_date'] ?? null;
        $metadata['note'] = $data['note'] ?? null;
        $metadata['edited_at'] = now()->toIso8601String();
        $metadata['edited_by'] = auth()->id();
        $metadata['edited_by_name'] = auth()->user()?->name;
        $metadata = array_merge($metadata, $this->actorMetadata('updated'));
        $metadata['paid_amount'] = $paidAmount;

        $sale->update([
            'contact_id' => $data['contact_id'] ?? null,
            'status' => in_array($sale->status, ['refunded', 'cancelled'], true) ? $sale->status : $expectedStatus,
            'metadata' => $metadata,
        ]);
        $sale->refresh();
        if ($sale->invoice) {
            $sale->invoice->update([
                'contact_id' => $sale->contact_id,
                'due_date' => $data['due_date'] ?? null,
                'status' => $this->invoiceStatusForSale($sale, $data['due_date'] ?? null),
                'metadata' => array_merge($sale->invoice->metadata ?? [], $this->actorMetadata('updated')),
            ]);
        }

        return back()->with('status', 'Vente '.$sale->number.' mise à jour.');
    }

    public function createSaleInvoice(Request $request, Sale $sale): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless($sale->tenant_id === $tenant->id, 404);

        $data = $request->validate([
            'due_date' => ['nullable', 'date'],
            'invoice_note' => ['nullable', 'string', 'max:500'],
        ]);

        $invoice = DB::transaction(function () use ($tenant, $sale, $data): SaleInvoice {
            $sale->loadMissing('invoice');
            $invoice = $sale->invoice;

            if (! $invoice) {
                $invoice = new SaleInvoice([
                    'tenant_id' => $tenant->id,
                    'sale_id' => $sale->id,
                    'number' => $this->nextSaleInvoiceNumber($tenant),
                    'issued_at' => now(),
                    'metadata' => array_merge(['source' => 'sales_list'], $this->creationActorMetadata()),
                ]);
            }

            $invoiceMetadata = $invoice->metadata ?? [];
            if (! isset($invoiceMetadata['created_by'])) {
                $invoiceMetadata['created_by'] = $invoice->user_id ?: auth()->id();
                $invoiceMetadata['created_by_name'] = $invoice->user?->name ?: auth()->user()?->name;
                $invoiceMetadata['created_by_at'] = $invoice->created_at?->toIso8601String() ?: now()->toIso8601String();
            }
            $invoiceMetadata = array_merge($invoiceMetadata, $this->actorMetadata('updated'));

            $invoice->fill([
                'contact_id' => $sale->contact_id,
                'user_id' => $invoice->user_id ?: auth()->id(),
                'status' => $this->invoiceStatusForSale($sale, $data['due_date'] ?? data_get($sale->metadata, 'due_date')),
                'due_date' => $data['due_date'] ?? data_get($sale->metadata, 'due_date'),
                'subtotal_amount' => $sale->subtotal_amount,
                'discount_amount' => $sale->discount_amount,
                'tax_amount' => $sale->tax_amount,
                'total_amount' => $sale->total_amount,
                'note' => $data['invoice_note'] ?? $invoice->note,
                'metadata' => $invoiceMetadata,
            ]);
            $invoice->save();

            return $invoice;
        });

        return redirect()
            ->route('module', ['module' => 'sales', 'section' => 'list', 'invoice' => $invoice->id])
            ->with('status', 'Facture '.$invoice->number.' prête.');
    }

    public function downloadSalePdf(Sale $sale): Response
    {
        $tenant = $this->tenant();
        abort_unless($sale->tenant_id === $tenant->id, 404);

        $sale->loadMissing(['contact', 'items.item', 'payments', 'invoice', 'user']);
        $paid = $this->salePaidAmount($sale);
        $settings = $this->documentSettings($tenant);

        return $this->downloadDocumentPdf($tenant, [
            'type' => 'sale',
            'title' => $settings['sale_title'],
            'number' => $sale->number,
            'date' => $sale->sold_at,
            'due_date' => data_get($sale->metadata, 'due_date'),
            'partner_label' => 'Client',
            'partner' => $sale->contact,
            'reference' => data_get($sale->metadata, 'reference_number'),
            'status' => $sale->status,
            'payment_method' => $sale->payment_method,
            'created_by' => data_get($sale->metadata, 'created_by_name') ?: $sale->user?->name,
            'updated_by' => data_get($sale->metadata, 'updated_by_name') ?: data_get($sale->metadata, 'edited_by_name'),
            'updated_at' => data_get($sale->metadata, 'updated_by_at') ?: data_get($sale->metadata, 'edited_at'),
            'lines' => $sale->items->map(fn (SaleItem $line): array => [
                'name' => $line->name,
                'code' => $line->item?->barcode ?? $line->item?->isbn ?? $line->item?->item_code ?? null,
                'quantity' => (float) $line->quantity,
                'unit_price' => (float) $line->unit_price,
                'total' => (float) $line->total_price,
            ])->values()->all(),
            'totals' => [
                'subtotal' => (float) $sale->subtotal_amount,
                'discount' => (float) $sale->discount_amount,
                'tax' => (float) $sale->tax_amount,
                'paid' => $paid,
                'due' => max(0, (float) $sale->total_amount - $paid),
                'total' => (float) $sale->total_amount,
            ],
            'note' => data_get($sale->metadata, 'note'),
            'template_note' => $settings['sale_note_template'],
            'filename' => 'vente-'.$sale->number.'.pdf',
        ]);
    }

    public function downloadInvoicePdf(SaleInvoice $invoice): Response
    {
        $tenant = $this->tenant();
        abort_unless($invoice->tenant_id === $tenant->id, 404);

        $invoice->loadMissing(['sale.items.item', 'sale.payments', 'sale.user', 'contact', 'user']);
        $sale = $invoice->sale;
        abort_unless($sale, 404);
        $paid = $this->salePaidAmount($sale);
        $invoiceStatus = $this->invoiceStatusForSale($sale, $invoice->due_date);
        if ($invoice->status !== $invoiceStatus) {
            $invoice->forceFill(['status' => $invoiceStatus])->save();
        }
        $settings = $this->documentSettings($tenant);

        return $this->downloadDocumentPdf($tenant, [
            'type' => 'invoice',
            'title' => $settings['invoice_title'],
            'number' => $invoice->number,
            'date' => $invoice->issued_at,
            'due_date' => $invoice->due_date,
            'partner_label' => 'Client',
            'partner' => $invoice->contact ?? $sale->contact,
            'reference' => data_get($sale->metadata, 'reference_number'),
            'sale_number' => $sale->number,
            'status' => $invoiceStatus,
            'payment_method' => $sale->payment_method,
            'created_by' => data_get($invoice->metadata, 'created_by_name') ?: $invoice->user?->name,
            'updated_by' => data_get($invoice->metadata, 'updated_by_name') ?: data_get($sale->metadata, 'updated_by_name') ?: $sale->user?->name,
            'updated_at' => data_get($invoice->metadata, 'updated_by_at') ?: data_get($sale->metadata, 'updated_by_at'),
            'lines' => $sale->items->map(fn (SaleItem $line): array => [
                'name' => $line->name,
                'code' => $line->item?->barcode ?? $line->item?->isbn ?? $line->item?->item_code ?? null,
                'quantity' => (float) $line->quantity,
                'unit_price' => (float) $line->unit_price,
                'total' => (float) $line->total_price,
            ])->values()->all(),
            'totals' => [
                'subtotal' => (float) $invoice->subtotal_amount,
                'discount' => (float) $invoice->discount_amount,
                'tax' => (float) $invoice->tax_amount,
                'paid' => $paid,
                'due' => max(0, (float) $invoice->total_amount - $paid),
                'total' => (float) $invoice->total_amount,
            ],
            'note' => $invoice->note,
            'template_note' => $settings['invoice_note_template'],
            'filename' => 'facture-'.$invoice->number.'.pdf',
        ]);
    }

    public function downloadPurchasePdf(Purchase $purchase): Response
    {
        $tenant = $this->tenant();
        abort_unless($purchase->tenant_id === $tenant->id, 404);

        $purchase->loadMissing(['supplier', 'items.item', 'user']);
        $settings = $this->documentSettings($tenant);
        $ordered = (int) $purchase->items->sum('quantity_ordered');
        $received = (int) $purchase->items->sum('quantity_received');

        return $this->downloadDocumentPdf($tenant, [
            'type' => 'purchase',
            'title' => $settings['purchase_title'],
            'number' => $purchase->number,
            'date' => $purchase->ordered_at,
            'due_date' => $purchase->expected_at,
            'partner_label' => 'Fournisseur',
            'partner' => $purchase->supplier,
            'reference' => data_get($purchase->metadata, 'supplier_invoice') ?: data_get($purchase->metadata, 'reference'),
            'status' => $purchase->status,
            'payment_method' => null,
            'created_by' => data_get($purchase->metadata, 'created_by_name') ?: $purchase->user?->name,
            'updated_by' => data_get($purchase->metadata, 'updated_by_name') ?: data_get($purchase->metadata, 'received_by_name'),
            'updated_at' => data_get($purchase->metadata, 'updated_by_at') ?: data_get($purchase->metadata, 'received_by_at'),
            'lines' => $purchase->items->map(fn (PurchaseItem $line): array => [
                'name' => $line->item?->title ?? 'Article supprimé',
                'code' => $line->item?->barcode ?? $line->item?->isbn ?? $line->item?->item_code ?? null,
                'quantity' => (float) $line->quantity_ordered,
                'received' => (float) $line->quantity_received,
                'unit_price' => (float) $line->unit_cost,
                'total' => (float) $line->quantity_ordered * (float) $line->unit_cost,
            ])->values()->all(),
            'totals' => [
                'subtotal' => (float) $purchase->total_amount,
                'discount' => 0.0,
                'tax' => 0.0,
                'paid' => 0.0,
                'due' => 0.0,
                'total' => (float) $purchase->total_amount,
                'ordered' => $ordered,
                'received' => $received,
            ],
            'note' => data_get($purchase->metadata, 'note'),
            'template_note' => $settings['purchase_note_template'],
            'filename' => 'achat-'.$purchase->number.'.pdf',
        ]);
    }

    public function destroySale(Sale $sale): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless($sale->tenant_id === $tenant->id, 404);

        if (in_array($sale->status, ['refunded', 'cancelled'], true)) {
            return back()->withErrors(['sale' => 'Cette vente est déjà clôturée.']);
        }

        $metadata = $sale->metadata ?? [];
        $metadata['cancelled'] = [
            'cancelled_at' => now()->toIso8601String(),
            'cancelled_by' => auth()->id(),
            'cancelled_by_name' => auth()->user()?->name,
            'reason' => 'Annulation depuis la liste des ventes',
        ];
        $metadata = array_merge($metadata, $this->actorMetadata('updated'));

            $sale->update([
                'status' => 'cancelled',
                'metadata' => $metadata,
            ]);
            $this->syncSaleInvoiceStatus($sale->fresh('invoice'));

        return back()->with('status', 'Vente '.$sale->number.' annulée.');
    }

    public function refundSale(Request $request, Sale $sale): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless($sale->tenant_id === $tenant->id, 404);
        if (in_array($sale->status, ['refunded', 'cancelled'], true)) {
            return back()->withErrors(['sale' => 'Cette vente est déjà clôturée.']);
        }

        $idempotencyKey = $this->idempotencyKey($request);

        $data = $request->validate([
            'refund_method' => ['required', 'in:cash,card,transfer,credit'],
            'refund_reason' => ['required', 'string', 'max:500'],
            'restock' => ['nullable', 'boolean'],
            'return_lines' => ['nullable', 'array'],
            'return_lines.*.sale_item_id' => ['required_with:return_lines', 'integer'],
            'return_lines.*.quantity' => ['nullable', 'integer', 'min:0'],
            'return_lines.*.stock_action' => ['nullable', 'in:restock,no_restock,damaged,lost,waste'],
            'return_lines.*.reason' => ['nullable', 'string', 'max:240'],
        ]);

        $sale = DB::transaction(function () use ($tenant, $sale, $data, $idempotencyKey): Sale {
            $existing = $this->findByIdempotencyKey(SaleReturn::class, $tenant->id, $idempotencyKey);
            if ($existing instanceof SaleReturn) {
                return $sale;
            }

            $sale = Sale::where('tenant_id', $tenant->id)->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            if (in_array($sale->status, ['refunded', 'cancelled'], true)) {
                throw new \RuntimeException('Cette vente est déjà clôturée.');
            }

            $inventoryService = app(\App\Services\Inventory\InventoryService::class);
            $returnLocationId = $inventoryService->locationIdFromName($tenant->id, null);

            $sale->load(['items.item', 'returns']);
            $soldLines = $sale->items->keyBy('id');
            $alreadyReturned = [];
            foreach ($sale->returns as $previousReturn) {
                foreach (($previousReturn->lines ?? []) as $previousLine) {
                    $saleItemId = (int) ($previousLine['sale_item_id'] ?? 0);
                    $alreadyReturned[$saleItemId] = ($alreadyReturned[$saleItemId] ?? 0) + (int) ($previousLine['quantity'] ?? 0);
                }
            }

            $requestedLines = collect($data['return_lines'] ?? [])
                ->mapWithKeys(fn (array $line, int|string $key) => [(int) ($line['sale_item_id'] ?? $key) => $line]);
            if ($requestedLines->isEmpty()) {
                $requestedLines = $soldLines->mapWithKeys(fn ($line) => [$line->id => [
                    'sale_item_id' => $line->id,
                    'quantity' => max(0, (int) $line->quantity - (int) ($alreadyReturned[$line->id] ?? 0)),
                    'stock_action' => ($data['restock'] ?? true) ? 'restock' : 'no_restock',
                    'reason' => null,
                ]]);
            }

            $lines = [];
            $stockActions = [];
            $returnTotal = 0.0;

            foreach ($requestedLines as $saleItemId => $requestedLine) {
                $line = $soldLines->get((int) $saleItemId);
                if (! $line) {
                    throw new \RuntimeException('Une ligne de retour ne correspond pas à cette vente.');
                }

                $remainingQuantity = max(0, (int) $line->quantity - (int) ($alreadyReturned[$line->id] ?? 0));
                $quantity = min($remainingQuantity, max(0, (int) ($requestedLine['quantity'] ?? 0)));
                if ($quantity <= 0) {
                    continue;
                }

                $stockAction = (string) ($requestedLine['stock_action'] ?? (($data['restock'] ?? true) ? 'restock' : 'no_restock'));
                $stockAction = in_array($stockAction, ['restock', 'no_restock', 'damaged', 'lost', 'waste'], true) ? $stockAction : 'no_restock';
                $lineReason = trim((string) ($requestedLine['reason'] ?? ''));
                if (in_array($stockAction, ['damaged', 'lost', 'waste'], true) && $lineReason === '') {
                    throw new \RuntimeException('Indiquez un motif pour les articles perdus, abîmés ou mis au rebut.');
                }

                $lineTotal = round(((float) $line->total_price / max(1, (int) $line->quantity)) * $quantity, 2);
                $returnTotal = round($returnTotal + $lineTotal, 2);
                $stockActions[] = $stockAction;

                $lines[] = [
                    'sale_item_id' => $line->id,
                    'item_id' => $line->item_id,
                    'name' => $line->name,
                    'quantity' => $quantity,
                    'max_quantity' => $remainingQuantity,
                    'unit_price' => (float) $line->unit_price,
                    'total_price' => $lineTotal,
                    'stock_action' => $stockAction,
                    'reason' => $lineReason,
                ];
            }

            if ($returnTotal <= 0.001 || empty($lines)) {
                throw new \RuntimeException('Sélectionnez au moins un article et une quantité à retourner.');
            }
            $alreadyReturnedAmount = (float) $sale->returns->sum('total_amount');
            if ($returnTotal - max(0, (float) $sale->total_amount - $alreadyReturnedAmount) > 0.001) {
                throw new \RuntimeException('Le montant du retour dépasse le montant encore remboursable.');
            }
            $stockDisposition = count(array_unique($stockActions)) === 1 ? $stockActions[0] : 'mixed';
            $allLinesFullyReturned = collect($lines)->every(function (array $returnLine) use ($alreadyReturned, $soldLines): bool {
                $soldLine = $soldLines->get((int) $returnLine['sale_item_id']);

                return ((int) ($alreadyReturned[$soldLine->id] ?? 0) + (int) $returnLine['quantity']) >= (int) $soldLine->quantity;
            }) && collect($soldLines)->every(function ($soldLine) use ($alreadyReturned, $lines): bool {
                $currentQuantity = collect($lines)->firstWhere('sale_item_id', $soldLine->id)['quantity'] ?? 0;

                return ((int) ($alreadyReturned[$soldLine->id] ?? 0) + (int) $currentQuantity) >= (int) $soldLine->quantity;
            });
            $isFullRefund = ($alreadyReturnedAmount + $returnTotal) + 0.001 >= (float) $sale->total_amount || $allLinesFullyReturned;

            $saleReturn = SaleReturn::create([
                'tenant_id' => $tenant->id,
                'sale_id' => $sale->id,
                'contact_id' => $sale->contact_id,
                'user_id' => auth()->id(),
                'number' => $this->nextReturnNumber($tenant),
                'status' => 'approved',
                'refund_method' => $data['refund_method'],
                'refund_scope' => $isFullRefund ? 'full' : 'partial',
                'total_amount' => $returnTotal,
                'lines' => $lines,
                'reason' => $data['refund_reason'] ?? null,
                'restock' => in_array('restock', $stockActions, true),
                'stock_disposition' => $stockDisposition,
                'returned_at' => now(),
                'idempotency_key' => $idempotencyKey,
                'metadata' => [
                    'already_returned_amount_before' => round($alreadyReturnedAmount, 2),
                    'refundable_amount_before' => round(max(0, (float) $sale->total_amount - $alreadyReturnedAmount), 2),
                ],
            ]);

            foreach ($lines as $returnLine) {
                if (! $returnLine['item_id']) {
                    continue;
                }

                $item = Item::whereKey($returnLine['item_id'])->lockForUpdate()->first();
                if (! $item || $item->type === 'service') {
                    continue;
                }

                $quantity = (int) $returnLine['quantity'];
                if ($returnLine['stock_action'] === 'restock') {
                    $inventoryService->move(new \App\Services\Inventory\MovementDTO(
                        tenantId: $tenant->id,
                        itemId: $item->id,
                        variantId: null,
                        locationId: $returnLocationId,
                        type: \App\Services\Inventory\InventoryMovementType::RETURN,
                        quantityChanged: $quantity,
                        unitCost: null,
                        referenceType: SaleReturn::class,
                        referenceId: $saleReturn->id,
                        referenceNumber: $saleReturn->number,
                        note: 'Retour vente '.$saleReturn->number.' · vente '.$sale->number,
                        reason: $returnLine['reason'] ?: $data['refund_reason'],
                        idempotencyKey: 'sale-return-'.$saleReturn->id.'-line-'.$returnLine['sale_item_id'].'-restock',
                    ));

                    $item->increment('stock_quantity', $quantity);
                    $item->refresh();
                    if ($item->status === 'out_of_stock' && $item->stock_quantity > 0) {
                        $item->update(['status' => 'active']);
                    }
                } else {
                    $stock = ItemLocationStock::query()
                        ->where('tenant_id', $tenant->id)
                        ->where('item_id', $item->id)
                        ->whereNull('variant_id')
                        ->where('location_id', $returnLocationId)
                        ->lockForUpdate()
                        ->first();
                    $quantitySnapshot = (int) ($stock?->quantity ?? $item->stock_quantity);
                    if ($stock && in_array($returnLine['stock_action'], ['damaged', 'lost', 'waste'], true)) {
                        $stock->increment('damaged_quantity', $quantity);
                    }

                    \App\Models\InventoryMovement::query()->create([
                        'tenant_id' => $tenant->id,
                        'item_id' => $item->id,
                        'variant_id' => null,
                        'location_id' => $returnLocationId,
                        'user_id' => auth()->id(),
                        'type' => match ($returnLine['stock_action']) {
                            'damaged', 'waste' => \App\Services\Inventory\InventoryMovementType::DAMAGE,
                            'lost' => \App\Services\Inventory\InventoryMovementType::LOSS,
                            default => \App\Services\Inventory\InventoryMovementType::REFUND_WITHOUT_RETURN,
                        },
                        'quantity_before' => $quantitySnapshot,
                        'quantity_delta' => 0,
                        'quantity_after' => $quantitySnapshot,
                        'reference_type' => SaleReturn::class,
                        'reference_id' => $saleReturn->id,
                        'reference_number' => $saleReturn->number,
                        'note' => 'Retour vente non restocké '.$saleReturn->number.' · vente '.$sale->number,
                        'reason' => $returnLine['reason'] ?: $data['refund_reason'],
                        'idempotency_key' => 'sale-return-'.$saleReturn->id.'-line-'.$returnLine['sale_item_id'].'-'.$returnLine['stock_action'],
                    ]);
                }
            }

            if ($data['refund_method'] === 'cash' && $session = app(CashRegisterService::class)->openSession($tenant, true)) {
                $movement = app(CashRegisterService::class)->recordMovement($tenant, $session, 'sale_refund_cash', 'out', $returnTotal, [
                    'sale_id' => $sale->id,
                    'reference' => $saleReturn->number,
                    'payment_method' => 'cash',
                    'note' => 'Remboursement espèces retour '.$saleReturn->number,
                    'metadata' => [
                        'sale_return_id' => $saleReturn->id,
                        'sale_return_number' => $saleReturn->number,
                        'sale_number' => $sale->number,
                    ],
                ]);
                $saleReturn->forceFill(['metadata' => array_merge($saleReturn->metadata ?? [], [
                    'cash_register' => [
                        'session_id' => $session->id,
                        'session_number' => $session->number,
                        'movement_id' => $movement->id,
                        'movement_number' => $movement->number,
                    ],
                ])])->save();
            }

            $metadata = $sale->metadata ?? [];
            $metadata['refund'] = [
                'method' => $data['refund_method'],
                'reason' => $data['refund_reason'] ?? null,
                'restock' => in_array('restock', $stockActions, true),
                'stock_disposition' => $stockDisposition,
                'scope' => $isFullRefund ? 'full' : 'partial',
                'amount' => $returnTotal,
                'total_refunded' => round($alreadyReturnedAmount + $returnTotal, 2),
                'refunded_at' => now()->toIso8601String(),
                'refunded_by' => auth()->id(),
                'refunded_by_name' => auth()->user()?->name,
            ];
            $metadata['return_history'][] = [
                'return_id' => $saleReturn->id,
                'return_number' => $saleReturn->number,
                'amount' => $returnTotal,
                'scope' => $isFullRefund ? 'full' : 'partial',
                'created_at' => now()->toIso8601String(),
            ];
            $metadata = array_merge($metadata, $this->actorMetadata('updated'));

            $sale->update([
                'status' => $isFullRefund ? 'refunded' : 'partially_refunded',
                'metadata' => $metadata,
            ]);
            if ($isFullRefund) {
                $this->syncSaleInvoiceStatus($sale->fresh('invoice'));
            }

            return $sale;
        });

        return back()->with('status', 'Retour enregistré pour la vente '.$sale->number.'.');
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

        $idempotencyKey = $this->idempotencyKey($request);

        DB::transaction(function () use ($tenant, $data, $idempotencyKey): void {
            $existing = $this->findByIdempotencyKey(SalePayment::class, $tenant->id, $idempotencyKey);
            if ($existing instanceof SalePayment) {
                return;
            }

            $sale = Sale::where('tenant_id', $tenant->id)->whereKey($data['sale_id'])->lockForUpdate()->firstOrFail();
            if (in_array($sale->status, ['paid', 'refunded', 'cancelled'], true)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'sale_id' => $sale->status === 'paid'
                        ? 'Cette vente est déjà entièrement payée.'
                        : 'Cette vente est clôturée et ne peut plus recevoir de paiement.',
                ]);
            }

            $paidBefore = $this->salePaidAmount($sale);
            $remaining = round((float) $sale->total_amount - $paidBefore, 2);
            if ($remaining <= 0.001) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'sale_id' => 'Cette vente est déjà entièrement payée.',
                ]);
            }

            $amount = round((float) $data['amount'], 2);
            if ($amount - $remaining > 0.001) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'amount' => 'Le montant reçu ne peut pas dépasser le reste à payer ('.number_format($remaining, 2, ',', ' ').' DH).',
                ]);
            }

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
                'idempotency_key' => $idempotencyKey,
            ]);

            $newStatus = $this->salePaymentStatus($sale, min((float) $sale->total_amount, round($paidBefore + $amount, 2)));

            // Convert reservations into actual stock deductions when the sale is fully paid.
            if ($newStatus === 'paid' && (bool) data_get($tenant->settings, 'pos.reserve_stock_for_unpaid_sales', false)) {
                $inventoryService = app(\App\Services\Inventory\InventoryService::class);
                $saleLocationId = $inventoryService->locationIdFromName($tenant->id, null);
                $reservations = \App\Models\InventoryMovement::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('reference_type', Sale::class)
                    ->where('reference_id', $sale->id)
                    ->where('type', \App\Services\Inventory\InventoryMovementType::RESERVATION)
                    ->get();

                foreach ($reservations as $reservation) {
                    $inventoryService->releaseReservation(
                        tenantId: $tenant->id,
                        itemId: $reservation->item_id,
                        variantId: $reservation->variant_id,
                        locationId: $reservation->location_id,
                        quantity: abs((int) $reservation->quantity_delta),
                        reason: 'Libération réservation vente '.$sale->number,
                        idempotencyKey: 'sale-release-'.$sale->id.'-item-'.$reservation->item_id,
                        referenceType: Sale::class,
                        referenceId: $sale->id,
                    );

                    $item = Item::whereKey($reservation->item_id)->lockForUpdate()->first();
                    if ($item && $item->type !== 'service') {
                        $inventoryService->move(new \App\Services\Inventory\MovementDTO(
                            tenantId: $tenant->id,
                            itemId: $item->id,
                            variantId: $reservation->variant_id,
                            locationId: $reservation->location_id,
                            type: \App\Services\Inventory\InventoryMovementType::SALE,
                            quantityChanged: -abs((int) $reservation->quantity_delta),
                            unitCost: null,
                            allowNegative: (bool) data_get($tenant->settings, 'pos.allow_oversell', false),
                            referenceType: Sale::class,
                            referenceId: $sale->id,
                            referenceNumber: $sale->number,
                            note: 'Vente manuelle '.$sale->number,
                        ));

                        $item->decrement('stock_quantity', abs((int) $reservation->quantity_delta));
                        $item->refresh();
                        if ($item->stock_quantity <= 0) {
                            $item->update(['status' => 'out_of_stock']);
                        }
                    }
                }
            }

            $metadata = $sale->metadata ?? [];
            $metadata['paid_amount'] = min((float) $sale->total_amount, round($paidBefore + $amount, 2));
            $metadata['last_payment'] = [
                'amount' => $amount,
                'method' => $data['method'],
                'received_at' => now()->toIso8601String(),
                'received_by' => auth()->id(),
                'received_by_name' => auth()->user()?->name,
            ];
            $metadata = array_merge($metadata, $this->actorMetadata('updated'));
            $sale->update([
                'status' => $newStatus,
                'metadata' => $metadata,
            ]);
            $this->syncSaleInvoiceStatus($sale->fresh('invoice'));
        });

        return back()->with('status', 'Paiement ajouté.');
    }

    public function storeDeliveryOrder(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $data = $request->validate([
            'sale_id' => ['required', 'integer', Rule::exists('sales', 'id')->where('tenant_id', $tenant->id)],
            'delivery_address' => ['required', 'string', 'max:1000'],
            'assigned_to' => ['nullable', 'string', 'max:160'],
            'scheduled_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $sale = Sale::where('tenant_id', $tenant->id)->with('contact')->whereKey($data['sale_id'])->firstOrFail();
        if (in_array($sale->status, ['cancelled', 'refunded'], true)) {
            return back()->withErrors(['sale_id' => 'Impossible de créer une livraison pour une vente clôturée.'])->withInput();
        }

        DeliveryOrder::create([
            'tenant_id' => $tenant->id,
            'sale_id' => $sale->id,
            'contact_id' => $sale->contact_id,
            'user_id' => auth()->id(),
            'number' => $this->nextDeliveryNumber($tenant),
            'status' => 'pending',
            'assigned_to' => $data['assigned_to'] ?? null,
            'delivery_address' => $data['delivery_address'],
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

    public function storeOnlineOrder(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless(AppModules::enabled($tenant, 'online_orders'), 404);

        $data = $request->validate([
            'contact_id' => ['nullable', 'integer', Rule::exists('contacts', 'id')->where('tenant_id', $tenant->id)->where('kind', 'client')],
            'customer_name' => ['nullable', 'string', 'max:180'],
            'customer_phone' => ['nullable', 'string', 'max:60'],
            'customer_email' => ['nullable', 'email', 'max:180'],
            'channel' => ['required', 'in:online,whatsapp,phone,in_store,marketplace,other'],
            'status' => ['required', 'in:pending,confirmed,preparing,ready,fulfilled,cancelled'],
            'ordered_at' => ['nullable', 'date'],
            'expected_at' => ['nullable', 'date'],
            'delivery_address' => ['nullable', 'string', 'max:1000'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'deposit_amount' => ['nullable', 'numeric', 'min:0'],
            'customer_note' => ['nullable', 'string', 'max:1000'],
            'internal_note' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['nullable', 'integer', Rule::exists('items', 'id')->where('tenant_id', $tenant->id)],
            'items.*.name' => ['nullable', 'string', 'max:220'],
            'items.*.quantity' => ['nullable', 'numeric', 'min:0.01'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.note' => ['nullable', 'string', 'max:300'],
        ]);

        $contact = null;
        if (! empty($data['contact_id'])) {
            $contact = Contact::where('tenant_id', $tenant->id)->where('kind', 'client')->whereKey($data['contact_id'])->firstOrFail();
        }

        if (! $contact && blank($data['customer_name'] ?? null)) {
            return back()->withErrors(['customer_name' => 'Choisissez un client ou saisissez le nom du client.'])->withInput();
        }

        $catalogItems = Item::where('tenant_id', $tenant->id)
            ->whereIn('id', collect($data['items'])->pluck('item_id')->filter()->unique())
            ->get()
            ->keyBy('id');

        $lines = collect($data['items'])
            ->map(function (array $line, int $index) use ($catalogItems): array {
                $itemId = (int) ($line['item_id'] ?? 0);
                $item = $itemId > 0 ? $catalogItems->get($itemId) : null;
                $name = trim((string) ($line['name'] ?? ''));
                $quantity = round(max(0, (float) ($line['quantity'] ?? 0)), 2);
                $unitPrice = round(max(0, (float) ($line['unit_price'] ?? ($item?->sale_price ?? 0))), 2);
                $discount = round(max(0, (float) ($line['discount_amount'] ?? 0)), 2);
                $lineSubtotal = round($quantity * $unitPrice, 2);
                $lineDiscount = min($discount, $lineSubtotal);

                return [
                    'item_id' => $item?->id,
                    'name' => $item?->title ?? $name,
                    'code' => $item?->barcode ?? $item?->isbn ?? $item?->sku ?? $item?->item_code,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'discount_amount' => $lineDiscount,
                    'total_amount' => round($lineSubtotal - $lineDiscount, 2),
                    'note' => trim((string) ($line['note'] ?? '')) ?: null,
                    'display_order' => $index + 1,
                ];
            })
            ->filter(fn (array $line) => $line['quantity'] > 0 && $line['name'] !== '')
            ->values();

        if ($lines->isEmpty()) {
            return back()->withErrors(['items' => 'Ajoutez au moins une ligne valide à la précommande.'])->withInput();
        }

        $subtotal = round($lines->sum(fn (array $line) => $line['quantity'] * $line['unit_price']), 2);
        $lineDiscount = round($lines->sum('discount_amount'), 2);
        $documentDiscount = min(round(max(0, (float) ($data['discount_amount'] ?? 0)), 2), max(0, $subtotal - $lineDiscount));
        $total = round(max(0, $subtotal - $lineDiscount - $documentDiscount), 2);
        $deposit = min(round(max(0, (float) ($data['deposit_amount'] ?? 0)), 2), $total);

        $order = DB::transaction(function () use ($tenant, $data, $contact, $lines, $subtotal, $lineDiscount, $documentDiscount, $total, $deposit): OnlineOrder {
            $order = OnlineOrder::create([
                'tenant_id' => $tenant->id,
                'contact_id' => $contact?->id,
                'user_id' => auth()->id(),
                'number' => $this->nextOnlineOrderNumber($tenant),
                'channel' => $data['channel'],
                'status' => $data['status'],
                'payment_status' => 'unpaid',
                'customer_name' => $contact?->name ?? $data['customer_name'],
                'customer_phone' => $data['customer_phone'] ?? $contact?->phone,
                'customer_email' => $data['customer_email'] ?? $contact?->email,
                'delivery_address' => $data['delivery_address'] ?? $contact?->address,
                'ordered_at' => ! empty($data['ordered_at']) ? Carbon::parse($data['ordered_at']) : now(),
                'expected_at' => $data['expected_at'] ?? null,
                'subtotal_amount' => $subtotal,
                'discount_amount' => round($lineDiscount + $documentDiscount, 2),
                'deposit_amount' => $deposit,
                'total_amount' => $total,
                'customer_note' => $data['customer_note'] ?? null,
                'internal_note' => $data['internal_note'] ?? null,
                'metadata' => [
                    ...$this->creationActorMetadata(),
                    'document_discount_amount' => $documentDiscount,
                    'line_discount_amount' => $lineDiscount,
                ],
            ]);

            foreach ($lines as $line) {
                $order->items()->create($line);
            }

            return $order;
        });

        return redirect()
            ->route('module', ['module' => 'online-orders', 'section' => 'list', 'order' => $order->id])
            ->with('status', 'Précommande '.$order->number.' créée.');
    }

    public function updateOnlineOrderStatus(Request $request, OnlineOrder $onlineOrder): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless(AppModules::enabled($tenant, 'online_orders'), 404);
        abort_unless($onlineOrder->tenant_id === $tenant->id, 404);

        $data = $request->validate([
            'status' => ['required', 'in:pending,confirmed,preparing,ready,fulfilled,cancelled'],
            'internal_note' => ['nullable', 'string', 'max:1000'],
        ]);

        if (! in_array($data['status'], $this->onlineOrderAllowedStatuses($onlineOrder), true)) {
            return back()->withErrors([
                'status' => 'Le passage de « '.$onlineOrder->status.' » vers « '.$data['status'].' » n’est pas autorisé. Choisissez l’étape suivante proposée.',
            ]);
        }

        $metadata = $onlineOrder->metadata ?? [];
        $metadata['status_history'] = collect($metadata['status_history'] ?? [])
            ->push([
                'from' => $onlineOrder->status,
                'to' => $data['status'],
                'payment_status' => $onlineOrder->payment_status,
                'user_id' => auth()->id(),
                'user_name' => auth()->user()?->name,
                'at' => now()->toIso8601String(),
                'note' => $data['internal_note'] ?? null,
            ])
            ->take(-30)
            ->values()
            ->all();
        $metadata = array_merge($metadata, $this->actorMetadata('updated'));

        $onlineOrder->update([
            'status' => $data['status'],
            'internal_note' => $data['internal_note'] ?? $onlineOrder->internal_note,
            'metadata' => $metadata,
        ]);

        return back()->with('status', 'Précommande '.$onlineOrder->number.' mise à jour.');
    }

    public function prepareOnlineOrderSale(OnlineOrder $onlineOrder): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless(AppModules::enabled($tenant, 'online_orders'), 404);
        abort_unless($onlineOrder->tenant_id === $tenant->id, 404);

        $existingSale = $onlineOrder->convertedSale
            ?? Sale::where('tenant_id', $tenant->id)->where('source_online_order_id', $onlineOrder->id)->first();

        if ($existingSale) {
            return redirect()
                ->route('module', ['module' => 'sales', 'section' => 'list', 'detail_sale' => $existingSale->id])
                ->with('status', 'La vente '.$existingSale->number.' existe déjà pour la précommande '.$onlineOrder->number.'.');
        }

        if (! $this->onlineOrderCanCreateSale($onlineOrder)) {
            return redirect()
                ->route('module', ['module' => 'online-orders', 'section' => 'list', 'order' => $onlineOrder->id])
                ->withErrors(['sale' => $this->onlineOrderSaleBlockReason($onlineOrder)]);
        }

        if ($onlineOrder->items()->whereNull('item_id')->exists()) {
            return redirect()
                ->route('module', ['module' => 'online-orders', 'section' => 'list', 'order' => $onlineOrder->id])
                ->withErrors(['sale' => 'Une ligne personnalisée n’est plus liée au catalogue. Associez-la à un article avant l’encaissement.']);
        }

        return redirect()->route('pos', ['source_online_order' => $onlineOrder->id]);
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
            ->route('module', ['module' => 'sales', 'section' => 'quotes', 'detail_quote' => $quotation->id])
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

    public function convertQuotationToSale(Quotation $quotation, InvoiceService $invoiceService): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless($quotation->tenant_id === $tenant->id, 404);

        $convertedInvoiceId = (int) data_get($quotation->metadata, 'converted_invoice_id', 0);
        if ($convertedInvoiceId > 0) {
            return redirect()
                ->route('module', ['module' => 'sales', 'section' => 'invoices', 'invoice' => $convertedInvoiceId])
                ->with('status', 'Ce devis est déjà converti en facture.');
        }

        if ($quotation->converted_sale_id) {
            return redirect()
                ->route('module', ['module' => 'sales', 'section' => 'quotes'])
                ->withErrors(['quotation' => 'Ce devis a déjà été converti par l’ancien flux de vente.']);
        }

        if ($quotation->status !== 'accepted') {
            return redirect()
                ->route('module', ['module' => 'sales', 'section' => 'quotes'])
                ->withErrors(['quotation' => 'Le devis doit être marqué "Accepté" avant conversion.']);
        }

        $invoice = DB::transaction(function () use ($tenant, $quotation, $invoiceService) {
            $quotation = Quotation::whereKey($quotation->id)->lockForUpdate()->firstOrFail();
            $convertedInvoiceId = (int) data_get($quotation->metadata, 'converted_invoice_id', 0);
            if ($convertedInvoiceId > 0) {
                return Invoice::whereKey($convertedInvoiceId)->firstOrFail();
            }

            $invoice = $invoiceService->create($tenant, [
                'customer_id' => $quotation->contact_id,
                'customer_snapshot' => [
                    'name' => $quotation->contact?->name ?? data_get($quotation->metadata, 'client_name', 'Client comptoir'),
                    'phone' => $quotation->contact?->phone ?? data_get($quotation->metadata, 'client_phone'),
                    'email' => $quotation->contact?->email,
                    'ice' => $quotation->contact?->ice,
                    'billing_address' => $quotation->contact?->address,
                ],
                'status' => 'draft',
                'issue_date' => now()->toDateString(),
                'due_date' => $quotation->expires_at?->toDateString() ?: now()->addDays(15)->toDateString(),
                'document_discount_type' => 'fixed',
                'document_discount_value' => $quotation->discount_amount,
                'customer_reference' => $quotation->number,
                'internal_note' => data_get($quotation->metadata, 'note'),
                'metadata' => ['source' => 'legacy_quotation', 'legacy_quotation_id' => $quotation->id],
                'lines' => collect($quotation->lines)->map(fn (array $line): array => [
                    'item_id' => $line['item_id'] ?? null,
                    'name' => $line['name'] ?? 'Ligne devis',
                    'quantity' => $line['quantity'] ?? 1,
                    'unit_price' => $line['unit_price'] ?? 0,
                    'tax_rate' => 0,
                    'tax_inclusive' => false,
                ])->all(),
            ]);

            $metadata = $quotation->metadata ?? [];
            $metadata['converted_invoice_id'] = $invoice->id;
            $metadata['converted_invoice_number'] = $invoice->number;
            $metadata['converted_at'] = now()->toIso8601String();
            $metadata['converted_by'] = auth()->id();
            $quotation->update(['status' => 'accepted', 'metadata' => $metadata]);

            return $invoice;
        });

        return redirect()
            ->route('module', ['module' => 'sales', 'section' => 'invoices', 'invoice' => $invoice->id])
            ->with('status', 'Devis '.$quotation->number.' converti en facture '.$invoice->number.'.');
    }

    public function destroyQuotation(Quotation $quotation): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless($quotation->tenant_id === $tenant->id, 404);

        if ($quotation->converted_sale_id) {
            return redirect()
                ->route('module', ['module' => 'sales', 'section' => 'quotes'])
                ->withErrors(['quotation' => 'Impossible de supprimer un devis déjà converti.']);
        }

        $number = $quotation->number;
        $quotation->delete();

        return redirect()
            ->route('module', ['module' => 'sales', 'section' => 'quotes'])
            ->with('status', 'Devis '.$number.' supprimé.');
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

        $reference = $data['reference'] ?? '';
        if ($reference === '') {
            $reference = $this->nextExpenseReference($tenant);
        }

        $expense = Expense::create([
            'tenant_id' => $tenant->id,
            'number' => $this->nextExpenseNumber($tenant),
            'category' => $data['category'],
            'label' => $data['label'],
            'amount' => round((float) $data['amount'], 2),
            'payment_method' => $data['payment_method'],
            'reference' => $reference,
            'note' => $data['note'] ?? null,
            'spent_at' => $data['spent_at'] ?? now()->toDateString(),
        ]);

        if ($data['payment_method'] === 'cash') {
            $session = $this->openCashRegisterSession($tenant);
            if ($session) {
                $this->recordCashRegisterMovement($tenant, $session, 'cash_out', 'out', (float) $expense->amount, [
                    'payment_method' => 'cash',
                    'reference' => $expense->number,
                    'note' => 'Dépense: '.$expense->label.' ('.$expense->category.')',
                    'moved_at' => $expense->spent_at,
                ]);
            }
        }

        return redirect()
            ->route('module', ['module' => 'finance', 'section' => 'expenses', 'detail_expense' => $expense->id])
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

        return redirect()->route('module', ['module' => 'finance', 'section' => 'accounts', 'detail_account' => $account->id])->with('status', 'Compte '.$account->name.' ajouté.');
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

        return redirect()->route('module', ['module' => 'finance', 'section' => 'deposits', 'detail_deposit' => $transaction->id])->with('status', 'Dépôt '.$transaction->number.' enregistré.');
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

        $transfer = DB::transaction(function () use ($tenant, $data): AccountTransaction {
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

            return $out;
        });

        return redirect()->route('module', ['module' => 'finance', 'section' => 'transfers', 'detail_transfer' => $transfer->id])->with('status', 'Transfert enregistré.');
    }

    public function openCashRegister(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $storeKeys = collect($this->storeCatalog($tenant))->pluck('key')->all();
        $data = $request->validate([
            'financial_account_id' => ['nullable', 'integer', Rule::exists('financial_accounts', 'id')->where('tenant_id', $tenant->id)->where('type', 'cash')],
            'store_key' => ['nullable', Rule::in($storeKeys)],
            'opening_amount' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'note' => ['nullable', 'string', 'max:700'],
        ]);

        $storeKey = $data['store_key'] ?? $this->currentStore($tenant)['key'];
        if ($this->openCashRegisterSession($tenant, false, $storeKey)) {
            return back()->withErrors(['cash_register' => 'Un tiroir est déjà ouvert pour ce magasin. Clôturez-le avant d’en ouvrir un autre.']);
        }

        $session = DB::transaction(function () use ($tenant, $data, $storeKey): CashRegisterSession {
            $session = CashRegisterSession::create([
                'tenant_id' => $tenant->id,
                'financial_account_id' => $data['financial_account_id'] ?? null,
                'opened_by' => auth()->id(),
                'store_key' => $storeKey,
                'number' => $this->nextCashRegisterSessionNumber($tenant),
                'status' => 'open',
                'opening_amount' => round((float) $data['opening_amount'], 2),
                'expected_cash_amount' => 0,
                'opened_at' => now(),
                'note' => $data['note'] ?? null,
            ]);

            $this->recordCashRegisterMovement($tenant, $session, 'opening', 'in', (float) $session->opening_amount, [
                'payment_method' => 'cash',
                'reference' => $session->number,
                'note' => 'Fond de caisse initial',
            ]);

            return $session;
        });

        return redirect()->route('module', 'cash-register')->with('status', 'Tiroir '.$session->number.' ouvert.');
    }

    public function storeCashRegisterMovement(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $data = $request->validate([
            'type' => ['required', Rule::in(['cash_in', 'cash_out', 'correction'])],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'reference' => ['nullable', 'string', 'max:160'],
            'note' => ['required', 'string', 'max:700'],
        ]);

        $session = $this->openCashRegisterSession($tenant, true);
        if (! $session) {
            return back()->withErrors(['cash_register' => 'Ouvrez le tiroir avant d’ajouter un mouvement.']);
        }

        $direction = $data['type'] === 'cash_out' ? 'out' : 'in';
        DB::transaction(function () use ($tenant, $session, $data, $direction): void {
            $lockedSession = CashRegisterSession::where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($session->id);
            $this->recordCashRegisterMovement($tenant, $lockedSession, $data['type'], $direction, round((float) $data['amount'], 2), [
                'payment_method' => 'cash',
                'reference' => $data['reference'] ?? null,
                'note' => $data['note'],
            ]);
        });

        return redirect()->route('module', 'cash-register')->with('status', 'Mouvement de tiroir enregistré.');
    }

    public function closeCashRegister(Request $request, CashRegisterSession $session): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless((int) $session->tenant_id === (int) $tenant->id, 404);
        if ($session->status !== 'open') {
            return back()->withErrors(['cash_register' => 'Ce tiroir est déjà clôturé.']);
        }

        $data = $request->validate([
            'counted_cash_amount' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'closing_note' => ['nullable', 'string', 'max:900'],
        ]);

        DB::transaction(function () use ($tenant, $session, $data): void {
            $lockedSession = CashRegisterSession::where('tenant_id', $tenant->id)->lockForUpdate()->findOrFail($session->id);
            $counted = round((float) $data['counted_cash_amount'], 2);
            $expected = round((float) $lockedSession->expected_cash_amount, 2);
            $difference = round($counted - $expected, 2);
            $lockedSession->update([
                'status' => 'closed',
                'closed_by' => auth()->id(),
                'counted_cash_amount' => $counted,
                'difference_amount' => $difference,
                'closed_at' => now(),
                'closing_note' => $data['closing_note'] ?? null,
            ]);

            CashRegisterMovement::create([
                'tenant_id' => $tenant->id,
                'cash_register_session_id' => $lockedSession->id,
                'user_id' => auth()->id(),
                'number' => $this->nextCashRegisterMovementNumber($tenant),
                'type' => 'closing',
                'direction' => 'neutral',
                'amount' => $counted,
                'balance_after' => $expected,
                'payment_method' => 'cash',
                'reference' => $lockedSession->number,
                'note' => 'Clôture du tiroir',
                'moved_at' => now(),
                'metadata' => [
                    'expected_cash_amount' => $expected,
                    'counted_cash_amount' => $counted,
                    'difference_amount' => $difference,
                ],
            ]);
        });

        return redirect()->route('module', 'cash-register')->with('status', 'Tiroir '.$session->number.' clôturé.');
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
            ->route('module', ['module' => 'finance', 'section' => 'advances', 'detail_advance' => $advance->id])
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
            'icon' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        ExpenseCategory::create([
            'tenant_id' => $tenant->id,
            'name' => $data['name'],
            'color' => $data['color'] ?? '#4F46E5',
            'icon' => $data['icon'] ?? null,
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

        $idempotencyKey = $this->idempotencyKey($request);

        $purchase = DB::transaction(function () use ($tenant, $data, $lines, $idempotencyKey): Purchase {
            $existing = $this->findByIdempotencyKey(Purchase::class, $tenant->id, $idempotencyKey);
            if ($existing instanceof Purchase) {
                return $existing;
            }

            $inventoryService = app(\App\Services\Inventory\InventoryService::class);
            $updateCostOnPurchase = (bool) data_get($tenant->settings, 'pos.update_cost_on_purchase', true);
            $receiveNow = $data['status'] === 'received';
            $locationId = $inventoryService->locationIdFromName($tenant->id, $data['warehouse'] ?? null);

            $supplier = Contact::where('tenant_id', $tenant->id)
                ->where('kind', 'supplier')
                ->whereKey($data['supplier_id'])
                ->lockForUpdate()
                ->firstOrFail();

            $items = Item::where('tenant_id', $tenant->id)
                ->whereIn('id', $lines->pluck('item_id'))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $total = $lines->sum(fn (array $line) => round($line['quantity'] * $line['unit_cost'], 2));
            $purchase = Purchase::create([
                'tenant_id' => $tenant->id,
                'supplier_id' => $data['supplier_id'],
                'user_id' => auth()->id(),
                'number' => $this->nextPurchaseNumber($tenant),
                'status' => $receiveNow ? 'received' : $data['status'],
                'total_amount' => $total,
                'ordered_at' => $data['ordered_at'] ?? now()->toDateString(),
                'expected_at' => $data['expected_at'] ?? null,
                'received_at' => $receiveNow ? now()->toDateString() : null,
                'idempotency_key' => $idempotencyKey,
                'metadata' => [
                    ...$this->creationActorMetadata(),
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
                    $inventoryService->move(new \App\Services\Inventory\MovementDTO(
                        tenantId: $tenant->id,
                        itemId: $item->id,
                        variantId: null,
                        locationId: $locationId,
                        type: \App\Services\Inventory\InventoryMovementType::PURCHASE,
                        quantityChanged: $line['quantity'],
                        unitCost: $line['unit_cost'],
                        referenceType: Purchase::class,
                        referenceId: $purchase->id,
                        referenceNumber: $purchase->number,
                        note: 'Achat '.$purchase->number,
                    ));

                    $item->increment('stock_quantity', $line['quantity']);
                    $item->update(array_filter([
                        'purchase_price' => $updateCostOnPurchase ? $line['unit_cost'] : null,
                        'status' => 'active',
                    ], fn ($value) => $value !== null));

                    $this->updateLocationCost($tenant->id, $item->id, null, $locationId, $line['unit_cost'], $line['quantity']);
                }
            }

            $supplier->increment('outstanding_balance', $purchase->total_amount);

            return $purchase;
        });

        return redirect()
            ->route('module', ['module' => 'purchases', 'section' => 'list', 'detail_purchase' => $purchase->id])
            ->with('status', 'Achat '.$purchase->number.' enregistré.');
    }

    public function receivePurchase(Request $request, Purchase $purchase): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless($purchase->tenant_id === $tenant->id, 404);

        $data = $request->validate([
            'received_at' => ['nullable', 'date'],
            'quantities' => ['nullable', 'array'],
            'quantities.*' => ['nullable', 'integer', 'min:0'],
            '_idempotency_key' => ['nullable', 'string', 'max:64'],
        ]);

        $batchKey = trim((string) ($data['_idempotency_key'] ?? ''));
        if ($batchKey === '') {
            // Fallback key depends on the actual quantities so that different receipt
            // batches are processed, while an identical resubmit is ignored.
            $batchKey = $request->method().$request->url().$request->ip().serialize($request->only('quantities', 'received_at'));
        }
        $batchKey = 'purchase-receive-'.$purchase->id.'-'.sha1($batchKey);

        $receivedAt = ! empty($data['received_at']) ? Carbon::parse($data['received_at'])->toDateString() : now()->toDateString();
        $requestedQuantities = collect($data['quantities'] ?? [])
            ->map(fn ($value, $key) => [(int) $key, max(0, (int) $value)])
            ->filter(fn ($pair) => $pair[0] > 0)
            ->mapWithKeys(fn ($pair) => [$pair[0] => $pair[1]]);

        $receipt = DB::transaction(function () use ($purchase, $tenant, $requestedQuantities, $receivedAt, $batchKey): array {
            $inventoryService = app(\App\Services\Inventory\InventoryService::class);
            $updateCostOnPurchase = (bool) data_get($tenant->settings, 'pos.update_cost_on_purchase', true);
            $locationId = $inventoryService->locationIdFromName($tenant->id, data_get($purchase->metadata, 'warehouse'));

            $metadata = $purchase->metadata ?? [];
            $receivedBatches = data_get($metadata, 'received_batches', []);
            if (in_array($batchKey, $receivedBatches, true)) {
                return ['purchase' => $purchase, 'received' => 0, 'skipped' => true];
            }

            $purchase = Purchase::where('tenant_id', $tenant->id)->whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            $purchase->load('items.item');

            $totalReceived = 0;
            foreach ($purchase->items as $line) {
                $remaining = max(0, $line->quantity_ordered - $line->quantity_received);
                if ($remaining <= 0 || ! $line->item) {
                    continue;
                }

                $requested = $requestedQuantities->get($line->id);
                if ($requested === null) {
                    // Backward compatibility: receive all remaining when no per-line input is supplied.
                    $requested = $remaining;
                }

                $toReceive = min($requested, $remaining);
                if ($toReceive <= 0) {
                    continue;
                }

                if ($line->item->type !== 'service') {
                    $inventoryService->move(new \App\Services\Inventory\MovementDTO(
                        tenantId: $tenant->id,
                        itemId: $line->item->id,
                        variantId: null,
                        locationId: $locationId,
                        type: \App\Services\Inventory\InventoryMovementType::PURCHASE,
                        quantityChanged: $toReceive,
                        unitCost: $line->unit_cost,
                        referenceType: Purchase::class,
                        referenceId: $purchase->id,
                        referenceNumber: $purchase->number,
                        note: 'Réception achat '.$purchase->number,
                        idempotencyKey: $batchKey.'-item-'.$line->id,
                    ));

                    $line->item->increment('stock_quantity', $toReceive);
                    $line->item->update(array_filter([
                        'purchase_price' => $updateCostOnPurchase ? $line->unit_cost : null,
                        'status' => 'active',
                    ], fn ($value) => $value !== null));

                    $this->updateLocationCost($tenant->id, $line->item->id, null, $locationId, $line->unit_cost, $toReceive);
                }
                $line->increment('quantity_received', $toReceive);
                $totalReceived += $toReceive;
            }

            $orderedTotal = (int) $purchase->items->sum('quantity_ordered');
            $receivedTotal = (int) $purchase->items->sum('quantity_received');
            $newStatus = $receivedTotal >= $orderedTotal ? 'received' : 'partially_received';

            $metadata = array_merge($metadata, $this->actorMetadata('updated'), [
                'received_by' => auth()->id(),
                'received_by_name' => auth()->user()?->name,
                'received_by_at' => now()->toIso8601String(),
                'received_batches' => array_merge($receivedBatches, [$batchKey]),
            ]);

            $purchase->update([
                'status' => $newStatus,
                'received_at' => $receivedAt,
                'metadata' => $metadata,
            ]);

            return ['purchase' => $purchase, 'received' => $totalReceived, 'skipped' => false];
        });

        if ($receipt['skipped']) {
            return back()->with('status', 'Cette réception a déjà été enregistrée.');
        }

        $message = $receipt['received'] > 0
            ? 'Achat '.$receipt['purchase']->number.' : '.$receipt['received'].' unité(s) réceptionnée(s).'
            : 'Achat '.$receipt['purchase']->number.' réceptionné.';

        return back()->with('status', $message);
    }

    public function storePurchasePayment(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $data = $request->validate([
            'purchase_id' => ['required', 'integer', Rule::exists('purchases', 'id')->where('tenant_id', $tenant->id)],
            'method' => ['required', 'in:cash,card,transfer,cheque,other'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'paid_at' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:160'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $idempotencyKey = $this->idempotencyKey($request);

        DB::transaction(function () use ($tenant, $data, $idempotencyKey): void {
            $existing = $this->findByIdempotencyKey(PurchasePayment::class, $tenant->id, $idempotencyKey);
            if ($existing instanceof PurchasePayment) {
                return;
            }

            $purchase = Purchase::where('tenant_id', $tenant->id)->whereKey($data['purchase_id'])->lockForUpdate()->firstOrFail();
            abort_if(in_array($purchase->status, ['cancelled'], true), 403, 'Cet achat est annulé.');

            $paidBefore = (float) $purchase->payments()->sum('amount');
            $remaining = round((float) $purchase->total_amount - $paidBefore, 2);
            $amount = round((float) $data['amount'], 2);

            if ($amount > $remaining + 0.001) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'amount' => 'Le montant ne peut pas dépasser le reste à payer ('.number_format($remaining, 2, ',', ' ').' DH).',
                ]);
            }

            PurchasePayment::create([
                'tenant_id' => $tenant->id,
                'purchase_id' => $purchase->id,
                'supplier_id' => $purchase->supplier_id,
                'user_id' => auth()->id(),
                'number' => $this->nextPurchasePaymentNumber($tenant),
                'method' => $data['method'],
                'amount' => $amount,
                'paid_at' => ! empty($data['paid_at']) ? Carbon::parse($data['paid_at']) : now(),
                'reference' => $data['reference'] ?? null,
                'note' => $data['note'] ?? null,
                'idempotency_key' => $idempotencyKey,
            ]);

            $paidAfter = $paidBefore + $amount;
            $paymentStatus = $paidAfter + 0.001 >= (float) $purchase->total_amount ? 'paid' : 'partial';
            $metadata = array_merge($purchase->metadata ?? [], [
                'payment_status' => $paymentStatus,
                'paid_amount' => $paidAfter,
            ]);

            $purchase->update([
                'metadata' => $metadata,
            ]);

            if ($purchase->supplier) {
                $purchase->supplier->decrement('outstanding_balance', $amount);
            }
        });

        return back()->with('status', 'Paiement fournisseur enregistré.');
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

        $idempotencyKey = $this->idempotencyKey($request);

        $return = DB::transaction(function () use ($tenant, $data, $lines, $idempotencyKey): PurchaseReturn {
            $existing = $this->findByIdempotencyKey(PurchaseReturn::class, $tenant->id, $idempotencyKey);
            if ($existing instanceof PurchaseReturn) {
                return $existing;
            }

            $inventoryService = app(\App\Services\Inventory\InventoryService::class);
            $purchase = Purchase::where('tenant_id', $tenant->id)->whereKey($data['purchase_id'])->lockForUpdate()->firstOrFail();
            $locationId = $inventoryService->locationIdFromName($tenant->id, data_get($purchase->metadata, 'warehouse'));
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
                    $inventoryService->move(new \App\Services\Inventory\MovementDTO(
                        tenantId: $tenant->id,
                        itemId: $item->id,
                        variantId: null,
                        locationId: $locationId,
                        type: \App\Services\Inventory\InventoryMovementType::PURCHASE_RETURN,
                        quantityChanged: -$line['quantity'],
                        unitCost: $line['unit_cost'],
                        referenceType: PurchaseReturn::class,
                        referenceId: null,
                        referenceNumber: null,
                        note: 'Retour achat · achat '.$purchase->number,
                    ));

                    $item->decrement('stock_quantity', $line['quantity']);
                }
            }

            $purchaseReturn = PurchaseReturn::create([
                'tenant_id' => $tenant->id,
                'purchase_id' => $purchase->id,
                'supplier_id' => $purchase->supplier_id,
                'number' => $this->nextPurchaseReturnNumber($tenant),
                'status' => 'completed',
                'total_amount' => round($total, 2),
                'returned_at' => ! empty($data['returned_at']) ? Carbon::parse($data['returned_at']) : now(),
                'reason' => $data['reason'] ?? null,
                'lines' => $payloadLines,
                'idempotency_key' => $idempotencyKey,
            ]);

            // Re-link movements to the now-created return document.
            \App\Models\InventoryMovement::query()
                ->where('tenant_id', $tenant->id)
                ->where('reference_type', PurchaseReturn::class)
                ->whereNull('reference_id')
                ->where('note', 'like', 'Retour achat · achat '.$purchase->number.'%')
                ->update([
                    'reference_id' => $purchaseReturn->id,
                    'reference_number' => $purchaseReturn->number,
                    'updated_at' => now(),
                ]);

            return $purchaseReturn;
        });

        return redirect()
            ->route('module', ['module' => 'purchases', 'section' => 'returns'])
            ->with('status', 'Retour achat '.$return->number.' enregistré.');
    }

    public function module(Request $request, string $module): View|RedirectResponse
    {
        $tenant = $this->tenant();
        $businessMode = BusinessMode::current($tenant);

        $modules = [
            'sales' => ['title' => 'Ventes', 'subtitle' => 'Historique, paiements, retours, livraisons et crédits client.', 'active' => 'sales'],
            'invoices' => ['title' => 'Facturation', 'subtitle' => 'Factures clients, devis, pro-forma, impressions PDF et relances.', 'active' => 'invoices'],
            'online-orders' => ['title' => 'Précommandes', 'subtitle' => 'Commandes en ligne, réservations WhatsApp, acompte et suivi de préparation.', 'active' => 'online_orders'],
            'purchases' => ['title' => 'Achats', 'subtitle' => 'Commandes fournisseurs, réception de stock et planification rentrée.', 'active' => 'purchases'],
            'loans' => ['title' => 'Emprunts', 'subtitle' => 'Prêts, retours, pénalités, réservations et cartes membre.', 'active' => 'loans'],
            'contacts' => ['title' => 'Contacts', 'subtitle' => 'Clients, écoles, fournisseurs, segmentation et communication.', 'active' => 'contacts'],
            'finance' => ['title' => 'Finances', 'subtitle' => 'Avances, coupons, dépenses, balances et clôture de caisse.', 'active' => 'finance'],
            'cash-register' => ['title' => 'Tiroir caisse', 'subtitle' => 'Ouverture, clôture, solde attendu et historique du tiroir.', 'active' => 'finance'],
            'reports' => ['title' => 'Rapports', 'subtitle' => 'Analytique ventes, inventaire, finances et bibliothèque.', 'active' => 'reports'],
            'settings' => ['title' => 'Paramètres', 'subtitle' => 'Profil '.$businessMode['short_label'].', utilisateurs, rôles, intégrations et sécurité.', 'active' => 'settings'],
        ];

        abort_unless(isset($modules[$module]), 404);

        $section = $request->query('section', 'list');
        if ($module === 'sales' && $section === 'add') {
            return redirect()->route('pos', array_filter([
                'source_invoice' => $request->query('from_invoice'),
                'source_online_order' => $request->query('from_order'),
            ]));
        }
        abort_unless(AppModules::enabled($tenant, AppModules::keyForModulePage($module, $section) ?? $module), 404);
        $sales = $this->salesListQuery($tenant, $request)->paginate(25)->withQueryString();
        $saleInvoices = SaleInvoice::query()
            ->with(['sale.contact', 'contact', 'user'])
            ->where('tenant_id', $tenant->id)
            ->when(trim((string) $request->query('q')) !== '', function (Builder $builder) use ($request): void {
                $query = trim((string) $request->query('q'));
                $builder->where(function (Builder $builder) use ($query): void {
                    $builder->where('number', 'like', "%{$query}%")
                        ->orWhereHas('sale', fn (Builder $sale) => $sale->where('number', 'like', "%{$query}%"))
                        ->orWhereHas('contact', fn (Builder $contact) => $contact->where('name', 'like', "%{$query}%"));
                });
            })
            ->latest('issued_at')
            ->paginate(25, ['*'], 'invoices_page')
            ->withQueryString();
        $commercialInvoices = Invoice::query()
            ->with(['customer', 'creator', 'payments', 'sourceSale'])
            ->where('tenant_id', $tenant->id)
            ->when($request->query('archived') !== 'with', fn (Builder $builder) => $builder->whereNull('archived_at'))
            ->when(trim((string) $request->query('q')) !== '', function (Builder $builder) use ($request): void {
                $query = trim((string) $request->query('q'));
                $builder->where(function (Builder $builder) use ($query): void {
                    $builder->where('number', 'like', "%{$query}%")
                        ->orWhere('status', 'like', "%{$query}%")
                        ->orWhere('customer_snapshot', 'like', "%{$query}%")
                        ->orWhereHas('customer', fn (Builder $contact) => $contact->where('name', 'like', "%{$query}%"));
                });
            })
            ->when($request->filled('invoice_status'), fn (Builder $builder) => $builder->where('status', $request->query('invoice_status')))
            ->latest('issue_date')
            ->latest('id')
            ->paginate(25, ['*'], 'commercial_invoices_page')
            ->withQueryString();
        $selectedCommercialInvoice = null;
        if ($module === 'invoices' && $request->filled('invoice')) {
            $selectedCommercialInvoice = Invoice::query()
                ->with(['customer', 'creator', 'updater', 'items', 'payments.user', 'sourceEstimate', 'duplicatedFrom', 'sourceSale'])
                ->where('tenant_id', $tenant->id)
                ->whereKey((int) $request->query('invoice'))
                ->first();
        }
        $salePrefillInvoice = null;
        $salePrefillOnlineOrder = null;
        if ($module === 'sales' && $section === 'add' && $request->filled('from_invoice')) {
            $salePrefillInvoice = Invoice::query()
                ->with(['customer', 'items', 'sourceSale'])
                ->where('tenant_id', $tenant->id)
                ->whereKey((int) $request->query('from_invoice'))
                ->first();
            if ($salePrefillInvoice?->sourceSale) {
                return redirect()
                    ->route('module', ['module' => 'sales', 'section' => 'list', 'detail_sale' => $salePrefillInvoice->sourceSale->id])
                    ->with('status', 'Une vente existe déjà pour la facture '.$salePrefillInvoice->number.'.');
            }
            if ($salePrefillInvoice && ! $this->invoiceCanCreateSale($salePrefillInvoice)) {
                return redirect()
                    ->route('module', ['module' => 'invoices', 'section' => 'invoices', 'invoice' => $salePrefillInvoice->id])
                ->withErrors(['invoice' => $this->invoiceCreateSaleBlockReason($salePrefillInvoice)]);
            }
        }
        if ($module === 'sales' && $section === 'add' && $request->filled('from_order')) {
            $salePrefillOnlineOrder = OnlineOrder::query()
                ->with(['contact', 'items.item.tax', 'convertedSale'])
                ->where('tenant_id', $tenant->id)
                ->whereKey((int) $request->query('from_order'))
                ->first();

            if ($salePrefillOnlineOrder?->convertedSale) {
                return redirect()
                    ->route('module', ['module' => 'sales', 'section' => 'list', 'detail_sale' => $salePrefillOnlineOrder->convertedSale->id])
                    ->with('status', 'Une vente existe déjà pour la précommande '.$salePrefillOnlineOrder->number.'.');
            }
            if ($salePrefillOnlineOrder && ! $this->onlineOrderCanCreateSale($salePrefillOnlineOrder)) {
                return redirect()
                    ->route('module', ['module' => 'online-orders', 'section' => 'list', 'order' => $salePrefillOnlineOrder->id])
                    ->withErrors(['sale' => $this->onlineOrderSaleBlockReason($salePrefillOnlineOrder)]);
            }
            if ($salePrefillOnlineOrder?->items->contains(fn (OnlineOrderItem $line) => ! $line->item_id)) {
                return redirect()
                    ->route('module', ['module' => 'online-orders', 'section' => 'list', 'order' => $salePrefillOnlineOrder->id])
                    ->withErrors(['sale' => 'Une ligne de la précommande n’est plus liée au catalogue.']);
            }
        }
        $quoteItems = Item::where('tenant_id', $tenant->id)
            ->with('tax')
            ->where('status', 'active')
            ->orderBy('title')
            ->take(350)
            ->get();
        if ($salePrefillInvoice || $salePrefillOnlineOrder) {
            $prefillItemIds = ($salePrefillInvoice?->items ?? $salePrefillOnlineOrder->items)
                ->pluck('item_id')
                ->filter()
                ->unique()
                ->values();
            $missingPrefillItemIds = $prefillItemIds->diff($quoteItems->pluck('id'))->values();

            if ($missingPrefillItemIds->isNotEmpty()) {
                $quoteItems = Item::where('tenant_id', $tenant->id)
                    ->with('tax')
                    ->whereIn('id', $missingPrefillItemIds)
                    ->orderBy('title')
                    ->get()
                    ->concat($quoteItems)
                    ->unique('id')
                    ->values();
            }
        }
        $commercialEstimates = Estimate::query()
            ->with(['customer', 'creator', 'convertedInvoice'])
            ->where('tenant_id', $tenant->id)
            ->when($request->query('archived') !== 'with', fn (Builder $builder) => $builder->whereNull('archived_at'))
            ->when(trim((string) $request->query('q')) !== '', function (Builder $builder) use ($request): void {
                $query = trim((string) $request->query('q'));
                $builder->where(function (Builder $builder) use ($query): void {
                    $builder->where('number', 'like', "%{$query}%")
                        ->orWhere('status', 'like', "%{$query}%")
                        ->orWhere('customer_snapshot', 'like', "%{$query}%")
                        ->orWhereHas('customer', fn (Builder $contact) => $contact->where('name', 'like', "%{$query}%"));
                });
            })
            ->when($request->filled('estimate_status'), fn (Builder $builder) => $builder->where('status', $request->query('estimate_status')))
            ->latest('issue_date')
            ->latest('id')
            ->paginate(25, ['*'], 'commercial_estimates_page')
            ->withQueryString();
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
        $onlineOrders = $this->onlineOrdersQuery($tenant, $request)->paginate(25, ['*'], 'online_orders_page')->withQueryString();
        $selectedOnlineOrder = null;
        if ($module === 'online-orders' && $request->filled('order')) {
            $selectedOnlineOrder = OnlineOrder::query()
                ->with(['contact', 'user', 'items.item', 'convertedSale', 'converter'])
                ->where('tenant_id', $tenant->id)
                ->whereKey((int) $request->query('order'))
                ->first();
        }
        $purchaseReturns = $this->purchaseReturnsQuery($tenant, $request)->paginate(25, ['*'], 'purchase_returns_page')->withQueryString();
        $expenses = $this->expensesQuery($tenant, $request)->paginate(25, ['*'], 'expenses_page')->withQueryString();
        $expenseCategories = $this->expenseCategoriesQuery($tenant, $request)->get();
        $customerAdvances = $this->customerAdvancesQuery($tenant, $request)->paginate(25, ['*'], 'advances_page')->withQueryString();
        $coupons = $this->couponsQuery($tenant, $request)->paginate(25, ['*'], 'coupons_page')->withQueryString();
        $discountRules = $this->discountRulesQuery($tenant, $request)->paginate(25, ['*'], 'discounts_page')->withQueryString();
        $discountItems = Item::where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->where('is_enabled', true)
            ->orderBy('title')
            ->take(1200)
            ->get(['id', 'title', 'item_code', 'barcode', 'isbn', 'sale_price', 'type']);
        $financialAccounts = FinancialAccount::where('tenant_id', $tenant->id)->orderByDesc('is_active')->orderBy('name')->get();
        $accountTransactions = $this->accountTransactionsQuery($tenant, $request)->paginate(25, ['*'], 'account_transactions_page')->withQueryString();
        $cashRegisterContext = $this->cashRegisterContext($tenant, $request);
        $reportContext = $this->reportContext($tenant, $request);
        $editContact = null;
        if ($module === 'contacts' && $request->filled('edit')) {
            $editContact = Contact::where('tenant_id', $tenant->id)->whereKey((int) $request->query('edit'))->first();
        }
        $purchasePrefillItemId = (int) $request->query('item');
        $purchaseItems = Item::where('tenant_id', $tenant->id)->where('status', 'active')->orderBy('title')->take(300)->get();
        if ($module === 'purchases' && $purchasePrefillItemId > 0 && ! $purchaseItems->contains('id', $purchasePrefillItemId)) {
            $prefillPurchaseItem = Item::where('tenant_id', $tenant->id)
                ->where('status', 'active')
                ->whereKey($purchasePrefillItemId)
                ->first();

            if ($prefillPurchaseItem) {
                $purchaseItems->prepend($prefillPurchaseItem);
            }
        }

        return view('librairepro.module', [
            'tenant' => $tenant,
            'active' => $modules[$module]['active'],
            'module' => $module,
            'section' => $section,
            'meta' => $modules[$module],
            'sales' => $module === 'sales' ? $sales : $tenant->sales()->with('contact')->latest('sold_at')->take(8)->get(),
            'saleInvoices' => $saleInvoices,
            'commercialInvoices' => $commercialInvoices,
            'selectedCommercialInvoice' => $selectedCommercialInvoice,
            'salePrefillInvoice' => $salePrefillInvoice,
            'salePrefillOnlineOrder' => $salePrefillOnlineOrder,
            'commercialEstimates' => $commercialEstimates,
            'salesTotals' => $salesTotals,
            'nextSaleNumber' => $module === 'sales' ? $this->peekSaleNumber($tenant) : null,
            'salesClients' => Contact::where('tenant_id', $tenant->id)->where('kind', 'client')->orderBy('name')->get(),
            'quotations' => $quotations,
            'quoteItems' => $quoteItems,
            'paymentSales' => $tenant->sales()->with('contact')->latest('sold_at')->take(80)->get(),
            'salePayments' => $this->salePaymentsQuery($tenant, $request)->paginate(25, ['*'], 'payments_page')->withQueryString(),
            'saleReturns' => $this->saleReturnsQuery($tenant, $request)->paginate(25, ['*'], 'returns_page')->withQueryString(),
            'deliveryOrders' => $this->deliveryOrdersQuery($tenant, $request)->paginate(25, ['*'], 'deliveries_page')->withQueryString(),
            'deliverySales' => $tenant->sales()->with('contact')->whereDoesntHave('deliveryOrders')->latest('sold_at')->take(80)->get(),
            'onlineOrders' => $onlineOrders,
            'selectedOnlineOrder' => $selectedOnlineOrder,
            'onlineOrderItems' => Item::where('tenant_id', $tenant->id)->where('is_enabled', true)->orderBy('title')->take(600)->get(),
            'nextOnlineOrderNumber' => $module === 'online-orders' ? $this->nextOnlineOrderNumber($tenant) : null,
            'purchases' => $module === 'purchases' ? $purchaseList : Purchase::where('tenant_id', $tenant->id)->with('supplier')->latest()->take(8)->get(),
            'purchaseReturns' => $purchaseReturns,
            'purchaseSuppliers' => Contact::where('tenant_id', $tenant->id)->where('kind', 'supplier')->orderBy('name')->get(),
            'purchaseItems' => $purchaseItems,
            'purchaseReturnSources' => Purchase::where('tenant_id', $tenant->id)->with(['supplier', 'items.item'])->whereIn('status', ['received', 'partially_received'])->latest('received_at')->take(80)->get(),
            'loans' => Loan::where('tenant_id', $tenant->id)->with(['member', 'item'])->latest()->take(8)->get(),
            'contacts' => Contact::where('tenant_id', $tenant->id)->orderBy('kind')->orderBy('name')->take(12)->get(),
            'contactStats' => [
                'clients' => Contact::where('tenant_id', $tenant->id)->where('kind', 'client')->count(),
                'suppliers' => Contact::where('tenant_id', $tenant->id)->where('kind', 'supplier')->count(),
                'receivable' => Contact::where('tenant_id', $tenant->id)->where('kind', 'client')->sum('outstanding_balance'),
                'advances' => Contact::where('tenant_id', $tenant->id)->where('kind', 'client')->sum('advance_balance'),
                'supplier_previous' => Contact::where('tenant_id', $tenant->id)->where('kind', 'supplier')->sum('opening_balance'),
                'supplier_purchases' => Purchase::where('tenant_id', $tenant->id)->where('status', '!=', 'cancelled')->sum('total_amount') + Contact::where('tenant_id', $tenant->id)->where('kind', 'supplier')->sum('outstanding_balance'),
                'supplier_returns' => PurchaseReturn::where('tenant_id', $tenant->id)->where('status', 'completed')->sum('total_amount') + Contact::where('tenant_id', $tenant->id)->where('kind', 'supplier')->sum('advance_balance'),
            ],
            'editContact' => $editContact,
            'expenses' => $module === 'finance' ? $expenses : Expense::where('tenant_id', $tenant->id)->latest('spent_at')->take(8)->get(),
            'expenseCategories' => $expenseCategories,
            'expenseTotals' => [
                'month' => Expense::where('tenant_id', $tenant->id)->whereDate('spent_at', '>=', now()->startOfMonth())->sum('amount'),
                'page' => $expenses->sum('amount'),
                'categories' => $expenseCategories->count(),
                'total' => Expense::where('tenant_id', $tenant->id)->sum('amount'),
            ],
            'financeClients' => Contact::where('tenant_id', $tenant->id)->where('kind', 'client')->orderBy('name')->get(),
            'customerAdvances' => $customerAdvances,
            'coupons' => $coupons,
            'discountRules' => $discountRules,
            'discountItems' => $discountItems,
            'discountStats' => [
                'active' => DiscountRule::where('tenant_id', $tenant->id)->active()->count(),
                'cart' => DiscountRule::where('tenant_id', $tenant->id)->where('scope', 'cart')->count(),
                'item' => DiscountRule::where('tenant_id', $tenant->id)->where('scope', 'item')->count(),
                'payment_limited' => DiscountRule::where('tenant_id', $tenant->id)->whereNotNull('payment_methods')->count(),
            ],
            'couponStats' => [
                'active' => Coupon::where('tenant_id', $tenant->id)->where('is_active', true)->where(function (Builder $builder): void {
                    $builder->whereNull('expires_at')->orWhereDate('expires_at', '>=', now()->toDateString());
                })->count(),
                'used_month' => Coupon::where('tenant_id', $tenant->id)->whereDate('updated_at', '>=', now()->startOfMonth())->sum('used_amount'),
                'customer' => Coupon::where('tenant_id', $tenant->id)->whereNotNull('contact_id')->count(),
                'page' => $coupons->sum('used_amount'),
            ],
            'couponAssignments' => $this->couponAssignments($tenant, $coupons->getCollection()),
            'discountAssignments' => $this->discountAssignments($tenant, $discountRules->getCollection()),
            'advanceStats' => [
                'balance' => Contact::where('tenant_id', $tenant->id)->where('kind', 'client')->sum('advance_balance'),
                'month' => CustomerAdvance::where('tenant_id', $tenant->id)->where('status', 'active')->whereDate('paid_at', '>=', now()->startOfMonth())->sum('amount'),
                'active_count' => CustomerAdvance::where('tenant_id', $tenant->id)->where('status', 'active')->count(),
                'page' => $customerAdvances->sum('amount'),
            ],
            'financialAccounts' => $financialAccounts,
            'accountTransactions' => $accountTransactions,
            'cashRegister' => $cashRegisterContext,
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
            'messagingConfig' => $this->messagingConfig($tenant),
            'messageTemplates' => $this->messageTemplates($tenant),
            'messagingContacts' => Contact::where('tenant_id', $tenant->id)->where('kind', 'client')->orderBy('name')->take(300)->get(),
            'messagingOutbox' => collect(data_get($tenant->settings, 'messaging_outbox', []))->take(80)->values(),
            'documentSettings' => $this->documentSettings($tenant),
            'stores' => $this->storeCatalog($tenant),
            'currentStore' => $this->currentStore($tenant),
            'storeAccessOptions' => $this->storeAccessOptions($tenant),
            'currentUserIsOwner' => $this->currentUserIsOwner($tenant),
            'demoMaintenanceStats' => $this->demoMaintenanceStats($tenant),
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

    private function posItemPayload(Item $item, \Illuminate\Support\Collection $topSold, bool $allowOversell): array
    {
        $image = collect($item->images)->first();
        $posStock = $item->type === 'service' ? 999999 : (int) ($item->pos_stock_quantity ?? $item->stock_quantity);
        $isOutOfStock = $item->type !== 'service' && $posStock <= 0;
        $isSellable = $allowOversell || $item->type === 'service' || ! $isOutOfStock;
        $primaryCode = $item->barcode ?? $item->isbn ?? $item->sku ?? $item->custom_barcode1 ?? $item->item_code;
        $searchText = collect([
            $item->title,
            $item->barcode,
            $item->isbn,
            $item->sku,
            $item->custom_barcode1,
            $item->item_code,
            $item->author,
            $item->editor,
            $item->description,
            $item->category?->name,
            $item->brand?->name,
            $item->unit?->name,
        ])->filter()->join(' ');

        return [
            'id' => $item->id,
            'name' => $item->title,
            'price' => (float) $item->sale_price,
            'stock' => $posStock,
            'global_stock' => $item->type === 'service' ? 999999 : (int) $item->stock_quantity,
            'sellable' => $isSellable,
            'out_of_stock' => $isOutOfStock,
            'type' => $item->type,
            'type_label' => $item->type === 'service' ? 'Service' : ($item->type === 'book' ? 'Livre' : 'Produit'),
            'category_id' => $item->category_id,
            'brand_id' => $item->brand_id,
            'unit_id' => $item->unit_id,
            'category_name' => $item->category?->name ?? 'Sans catégorie',
            'brand_name' => $item->brand?->name,
            'unit_name' => $item->unit?->name,
            'low_threshold' => (int) $item->min_stock_threshold,
            'sold' => (int) ($topSold[$item->id] ?? 0),
            'barcode' => $primaryCode,
            'image_url' => $image ? asset('storage/'.$image) : null,
            'stock_url' => route('catalog', ['panel' => 'stock-adjustment-add', 'stock_q' => $primaryCode ?? $item->title]),
            'search' => Str::lower($searchText),
        ];
    }

    private function stockItemOptionPayload(Item $item): array
    {
        $code = $item->barcode ?? $item->isbn ?? $item->sku ?? $item->item_code;
        $labelParts = collect([
            $item->title,
            'stock '.$item->stock_quantity,
            $code,
            $item->category?->name,
        ])->filter();

        return [
            'value' => (string) $item->id,
            'text' => $labelParts->join(' · '),
            'title' => $item->title,
            'stock' => (int) $item->stock_quantity,
            'threshold' => (int) $item->min_stock_threshold,
            'code' => $code,
            'category' => $item->category?->name,
            'brand' => $item->brand?->name,
            'purchase_price' => (float) $item->purchase_price,
        ];
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

        $purchaseCost = $saleItems->sum(fn ($line) => (float) $line->total_cost);
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

    private function couponValidation(Tenant $tenant, Request $request, ?Coupon $coupon = null): \Illuminate\Validation\Validator
    {
        return validator($request->all(), [
            'contact_id' => ['nullable', 'integer', Rule::exists('contacts', 'id')->where('tenant_id', $tenant->id)],
            'name' => ['nullable', 'string', 'max:160'],
            'code' => ['required', 'string', 'max:80', Rule::unique('coupons', 'code')->where('tenant_id', $tenant->id)->ignore($coupon?->id)],
            'type' => ['required', 'in:percent,fixed'],
            'value' => ['required', 'numeric', 'min:0.01', 'max:999999'],
            'minimum_amount' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'max_uses' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'expires_at' => ['nullable', 'date'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    private function couponsQuery(Tenant $tenant, Request $request): Builder
    {
        $q = trim((string) $request->query('q'));
        $status = (string) $request->query('coupon_status', 'all');
        $scope = (string) $request->query('coupon_scope', 'all');
        $section = (string) $request->query('section', '');

        return Coupon::query()
            ->with('contact')
            ->where('tenant_id', $tenant->id)
            ->when($q !== '', fn (Builder $builder) => $builder->where(function (Builder $builder) use ($q): void {
                $builder->where('code', 'like', "%{$q}%")
                    ->orWhere('name', 'like', "%{$q}%")
                    ->orWhereHas('contact', fn (Builder $contact) => $contact->where('name', 'like', "%{$q}%"));
            }))
            ->when($status === 'active', fn (Builder $builder) => $builder->where('is_active', true)->where(function (Builder $builder): void {
                $builder->whereNull('expires_at')->orWhereDate('expires_at', '>=', now()->toDateString());
            }))
            ->when($status === 'expired', fn (Builder $builder) => $builder->whereDate('expires_at', '<', now()->toDateString()))
            ->when($status === 'inactive', fn (Builder $builder) => $builder->where('is_active', false))
            ->when($scope === 'customer' || $section === 'customer-coupons', fn (Builder $builder) => $builder->whereNotNull('contact_id'))
            ->when($scope === 'public', fn (Builder $builder) => $builder->whereNull('contact_id'))
            ->latest();
    }

    private function discountRuleValidation(Tenant $tenant, Request $request, ?DiscountRule $discountRule = null): \Illuminate\Validation\Validator
    {
        return validator($request->all(), [
            'name' => ['required', 'string', 'max:160'],
            'code' => ['nullable', 'string', 'max:80', Rule::unique('discount_rules', 'code')->where('tenant_id', $tenant->id)->ignore($discountRule?->id)],
            'type' => ['required', 'in:percentage,fixed,percent'],
            'value' => ['required', 'numeric', 'min:0.01', 'max:999999'],
            'scope' => ['required', 'in:cart,item'],
            'minimum_amount' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'included_item_ids' => ['nullable', 'array'],
            'included_item_ids.*' => ['integer', Rule::exists('items', 'id')->where('tenant_id', $tenant->id)],
            'excluded_item_ids' => ['nullable', 'array'],
            'excluded_item_ids.*' => ['integer', Rule::exists('items', 'id')->where('tenant_id', $tenant->id)],
            'payment_methods' => ['nullable', 'array'],
            'payment_methods.*' => ['string', 'in:cash,card,transfer,advance,cheque,other'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    private function preparedDiscountRuleData(Tenant $tenant, array $data, Request $request): array
    {
        $included = collect($data['included_item_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();
        $excluded = collect($data['excluded_item_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->diff($included)
            ->values();

        $data['name'] = trim($data['name']);
        $data['code'] = filled($data['code'] ?? null) ? Str::upper(trim((string) $data['code'])) : null;
        $data['type'] = ($data['type'] ?? 'fixed') === 'percent' ? 'percentage' : $data['type'];
        $data['value'] = round((float) $data['value'], 2);
        $data['minimum_amount'] = round((float) ($data['minimum_amount'] ?? 0), 2);
        $data['included_item_ids'] = $included->isEmpty() ? null : $included->all();
        $data['excluded_item_ids'] = $excluded->isEmpty() ? null : $excluded->all();
        $data['payment_methods'] = collect($data['payment_methods'] ?? [])->filter()->unique()->values()->all() ?: null;
        $data['is_active'] = $request->boolean('is_active');
        $data['notes'] = filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null;

        return $data;
    }

    private function discountRulesQuery(Tenant $tenant, Request $request): Builder
    {
        $q = trim((string) $request->query('q'));
        $status = (string) $request->query('discount_status', 'all');
        $scope = (string) $request->query('discount_scope', 'all');

        return DiscountRule::query()
            ->where('tenant_id', $tenant->id)
            ->when($q !== '', fn (Builder $builder) => $builder->where(function (Builder $builder) use ($q): void {
                $builder->where('name', 'like', "%{$q}%")
                    ->orWhere('code', 'like', "%{$q}%")
                    ->orWhere('notes', 'like', "%{$q}%");
            }))
            ->when($status === 'active', fn (Builder $builder) => $builder->active())
            ->when($status === 'inactive', fn (Builder $builder) => $builder->where('is_active', false))
            ->when($status === 'expired', fn (Builder $builder) => $builder->whereDate('ends_at', '<', now()->toDateString()))
            ->when(in_array($scope, ['cart', 'item'], true), fn (Builder $builder) => $builder->where('scope', $scope))
            ->latest();
    }

    private function couponAssignments(Tenant $tenant, \Illuminate\Support\Collection $coupons): array
    {
        if ($coupons->isEmpty()) {
            return [];
        }

        $couponIds = $coupons->pluck('id')->map(fn ($id) => (int) $id)->all();
        $couponCodesById = $coupons->mapWithKeys(fn (Coupon $coupon) => [
            $coupon->id => Str::upper((string) $coupon->code),
        ])->all();
        $couponCodes = array_values(array_filter($couponCodesById));

        $pivotRows = DB::table('coupon_sale')
            ->join('sales', 'coupon_sale.sale_id', '=', 'sales.id')
            ->where('coupon_sale.tenant_id', $tenant->id)
            ->whereIn('coupon_sale.coupon_id', $couponIds)
            ->select('coupon_sale.coupon_id', 'coupon_sale.amount_applied', 'sales.id', 'sales.number', 'sales.total_amount', 'sales.sold_at')
            ->orderByDesc('sales.sold_at')
            ->get()
            ->groupBy('coupon_id');

        $metadataSales = Sale::query()
            ->where('tenant_id', $tenant->id)
            ->where('discount_amount', '>', 0)
            ->latest('sold_at')
            ->take(1000)
            ->get(['id', 'number', 'total_amount', 'sold_at', 'metadata']);

        $ticketRows = empty($couponCodes)
            ? collect()
            : PosTicket::query()
                ->where('tenant_id', $tenant->id)
                ->whereIn(DB::raw('upper(coupon_code)'), $couponCodes)
                ->latest('held_at')
                ->take(400)
                ->get(['id', 'number', 'total_amount', 'coupon_code', 'coupon_amount', 'converted_sale_id', 'held_at'])
                ->groupBy(fn (PosTicket $ticket) => Str::upper((string) $ticket->coupon_code));

        return $coupons->mapWithKeys(function (Coupon $coupon) use ($pivotRows, $metadataSales, $ticketRows): array {
            $rows = collect();
            $couponCode = Str::upper((string) $coupon->code);

            foreach (($pivotRows[$coupon->id] ?? collect()) as $row) {
                $rows->push([
                    'type' => 'sale',
                    'id' => (int) $row->id,
                    'number' => $row->number,
                    'total' => (float) $row->total_amount,
                    'amount' => (float) $row->amount_applied,
                    'date' => $row->sold_at,
                ]);
            }

            foreach ($metadataSales as $sale) {
                $metadataCouponId = (int) data_get($sale->metadata, 'discount.coupon.coupon_id');
                $metadataCouponCode = Str::upper((string) data_get($sale->metadata, 'discount.coupon.code'));

                if ($metadataCouponId !== (int) $coupon->id && $metadataCouponCode !== $couponCode) {
                    continue;
                }

                $rows->push([
                    'type' => 'sale',
                    'id' => (int) $sale->id,
                    'number' => $sale->number,
                    'total' => (float) $sale->total_amount,
                    'amount' => (float) data_get($sale->metadata, 'discount.coupon.amount', 0),
                    'date' => $sale->sold_at,
                ]);
            }

            foreach (($ticketRows[$couponCode] ?? collect()) as $ticket) {
                $rows->push([
                    'type' => $ticket->converted_sale_id ? 'sale' : 'ticket',
                    'id' => (int) ($ticket->converted_sale_id ?: $ticket->id),
                    'number' => $ticket->number,
                    'total' => (float) $ticket->total_amount,
                    'amount' => (float) $ticket->coupon_amount,
                    'date' => $ticket->held_at,
                ]);
            }

            $deduped = $rows
                ->unique(fn (array $row) => $row['type'].'-'.$row['id'])
                ->sortByDesc('date')
                ->values();

            return [$coupon->id => [
                'count' => $deduped->count(),
                'list' => $deduped->take(8)->values()->all(),
            ]];
        })->all();
    }

    private function discountAssignments(Tenant $tenant, \Illuminate\Support\Collection $discountRules): array
    {
        if ($discountRules->isEmpty()) {
            return [];
        }

        $ruleIds = $discountRules->pluck('id')->map(fn ($id) => (int) $id)->all();

        $pivotRows = DB::table('discount_rule_sale')
            ->join('sales', 'discount_rule_sale.sale_id', '=', 'sales.id')
            ->where('discount_rule_sale.tenant_id', $tenant->id)
            ->whereIn('discount_rule_sale.discount_rule_id', $ruleIds)
            ->select('discount_rule_sale.discount_rule_id', 'discount_rule_sale.amount_applied', 'sales.id', 'sales.number', 'sales.total_amount', 'sales.sold_at')
            ->orderByDesc('sales.sold_at')
            ->get()
            ->groupBy('discount_rule_id');

        $metadataSales = Sale::query()
            ->where('tenant_id', $tenant->id)
            ->where('discount_amount', '>', 0)
            ->latest('sold_at')
            ->take(1000)
            ->get(['id', 'number', 'total_amount', 'sold_at', 'metadata']);

        return $discountRules->mapWithKeys(function (DiscountRule $rule) use ($pivotRows, $metadataSales): array {
            $rows = collect();

            foreach (($pivotRows[$rule->id] ?? collect()) as $row) {
                $rows->push([
                    'type' => 'sale',
                    'id' => (int) $row->id,
                    'number' => $row->number,
                    'total' => (float) $row->total_amount,
                    'amount' => (float) $row->amount_applied,
                    'date' => $row->sold_at,
                ]);
            }

            foreach ($metadataSales as $sale) {
                if ((int) data_get($sale->metadata, 'discount.rule.rule_id') !== (int) $rule->id) {
                    continue;
                }

                $rows->push([
                    'type' => 'sale',
                    'id' => (int) $sale->id,
                    'number' => $sale->number,
                    'total' => (float) $sale->total_amount,
                    'amount' => (float) data_get($sale->metadata, 'discount.rule.amount', 0),
                    'date' => $sale->sold_at,
                ]);
            }

            $deduped = $rows
                ->unique(fn (array $row) => $row['type'].'-'.$row['id'])
                ->sortByDesc('date')
                ->values();

            return [$rule->id => [
                'count' => $deduped->count(),
                'list' => $deduped->take(8)->values()->all(),
            ]];
        })->all();
    }

    private function discountRulePayload(DiscountRule $rule): array
    {
        return [
            'id' => $rule->id,
            'name' => $rule->name,
            'code' => $rule->code,
            'type' => $rule->type === 'percent' ? 'percentage' : $rule->type,
            'value' => (float) $rule->value,
            'scope' => $rule->scope,
            'minimum_amount' => (float) $rule->minimum_amount,
            'included_item_ids' => collect($rule->included_item_ids ?? [])->map(fn ($id) => (int) $id)->values()->all(),
            'excluded_item_ids' => collect($rule->excluded_item_ids ?? [])->map(fn ($id) => (int) $id)->values()->all(),
            'payment_methods' => collect($rule->payment_methods ?? [])->filter()->values()->all(),
        ];
    }

    private function discountRuleDiscountForCart(Tenant $tenant, ?int $ruleId, \Illuminate\Support\Collection $lineItems, array $items, array $paymentMethods, float $maxSubtotal): array
    {
        $empty = [
            'valid' => false,
            'rule_id' => null,
            'name' => null,
            'type' => null,
            'value' => 0.0,
            'scope' => null,
            'amount' => 0.0,
            'eligible_subtotal' => 0.0,
            'message' => null,
            'capped' => false,
        ];

        if (! $ruleId) {
            return $empty;
        }

        $rule = DiscountRule::where('tenant_id', $tenant->id)->whereKey($ruleId)->active()->first();
        if (! $rule) {
            throw new \RuntimeException('Cette remise est indisponible ou expirée.');
        }

        $allowedMethods = collect($rule->payment_methods ?? [])->filter()->values();
        if ($allowedMethods->isNotEmpty() && $allowedMethods->intersect($paymentMethods)->isEmpty()) {
            throw new \RuntimeException('Cette remise n’est pas valable pour le mode de paiement sélectionné.');
        }

        $included = collect($rule->included_item_ids ?? [])->map(fn ($id) => (int) $id)->filter()->values();
        $excluded = collect($rule->excluded_item_ids ?? [])->map(fn ($id) => (int) $id)->filter()->values();

        $eligibleSubtotal = 0.0;
        foreach ($lineItems as $line) {
            $itemId = (int) $line['item_id'];
            if ($included->isNotEmpty() && ! $included->contains($itemId)) {
                continue;
            }
            if ($excluded->contains($itemId)) {
                continue;
            }

            $item = $items[$itemId] ?? null;
            $unitPrice = $line['unit_price'] ?? (float) ($item?->sale_price ?? 0);
            $eligibleSubtotal += round($unitPrice * (int) $line['quantity'], 2);
        }

        $eligibleSubtotal = round($eligibleSubtotal, 2);
        if ($eligibleSubtotal <= 0.0) {
            throw new \RuntimeException('Aucun article du panier n’est éligible à cette remise.');
        }

        if ((float) $rule->minimum_amount > $eligibleSubtotal) {
            throw new \RuntimeException('Montant minimum requis pour cette remise: '.$this->money($rule->minimum_amount).'.');
        }

        $detail = $this->discountForSubtotal($eligibleSubtotal, $rule->type, (float) $rule->value);
        $amount = min((float) $detail['amount'], max(0, $maxSubtotal));

        return [
            'valid' => true,
            'rule_id' => $rule->id,
            'name' => $rule->name,
            'code' => $rule->code,
            'type' => $detail['type'],
            'value' => $detail['value'],
            'scope' => $rule->scope,
            'amount' => round($amount, 2),
            'eligible_subtotal' => $eligibleSubtotal,
            'message' => 'Remise '.$rule->name.' appliquée: '.$this->money($amount).'.',
            'capped' => $detail['capped'] || $amount < (float) $detail['amount'],
        ];
    }

    private function cartTotals(Tenant $tenant, \Illuminate\Support\Collection $lineItems, float|array $discount = 0, ?string $couponCode = null, ?Contact $contact = null, ?int $discountRuleId = null, array $paymentMethods = []): array
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

        $discountInput = is_array($discount) ? $discount : ['type' => 'fixed', 'value' => (float) $discount];
        $couponDetail = $this->couponDiscountForSubtotal($tenant, $couponCode, $subtotal, $contact);
        $afterCoupon = max(0, $subtotal - $couponDetail['amount']);
        $ruleDetail = $this->discountRuleDiscountForCart($tenant, $discountRuleId, $lineItems, $items->all(), $paymentMethods, $afterCoupon);
        $manualDiscountDetail = $ruleDetail['valid']
            ? $this->discountForSubtotal($afterCoupon, 'fixed', 0)
            : $this->discountForSubtotal($afterCoupon, $discountInput['type'] ?? 'fixed', (float) ($discountInput['value'] ?? 0));
        $totalDiscount = min($subtotal, round($couponDetail['amount'] + $ruleDetail['amount'] + $manualDiscountDetail['amount'], 2));
        $total = max(0, round($subtotal - $totalDiscount, 2));

        return [
            'subtotal' => round($subtotal, 2),
            'discount' => $totalDiscount,
            'discount_type' => $ruleDetail['valid'] ? $ruleDetail['type'] : $manualDiscountDetail['type'],
            'discount_value' => $ruleDetail['valid'] ? $ruleDetail['value'] : $manualDiscountDetail['value'],
            'discount_capped' => $manualDiscountDetail['capped'] || $couponDetail['capped'] || $ruleDetail['capped'],
            'manual_discount' => $manualDiscountDetail,
            'rule_discount' => $ruleDetail,
            'coupon' => $couponDetail,
            'tax' => round($total * 0.2 / 1.2, 2),
            'total' => $total,
        ];
    }

    private function normalizedDiscountInput(array $data): array
    {
        $type = str((string) ($data['discount_type'] ?? 'fixed'))->lower()->value();
        $type = in_array($type, ['percentage', 'percent'], true) ? 'percentage' : 'fixed';
        $value = array_key_exists('discount_value', $data)
            ? (float) $data['discount_value']
            : (float) ($data['discount_amount'] ?? 0);

        return [
            'type' => $type,
            'value' => round(max(0, $value), 2),
        ];
    }

    private function discountForSubtotal(float $subtotal, string $type, float $value): array
    {
        $subtotal = round(max(0, $subtotal), 2);
        $type = in_array($type, ['percentage', 'percent'], true) ? 'percentage' : 'fixed';
        $value = round(max(0, $value), 2);
        $effectiveValue = $type === 'percentage' ? min($value, 100) : $value;
        $requestedAmount = $type === 'percentage'
            ? round($subtotal * ($value / 100), 2)
            : $value;
        $amount = $type === 'percentage'
            ? round($subtotal * ($effectiveValue / 100), 2)
            : $effectiveValue;
        $amount = min($amount, $subtotal);

        return [
            'type' => $type,
            'value' => $effectiveValue,
            'requested_value' => $value,
            'amount' => round($amount, 2),
            'requested_amount' => round($requestedAmount, 2),
            'capped' => $value !== $effectiveValue || $requestedAmount > $subtotal,
        ];
    }

    private function couponDiscountForSubtotal(Tenant $tenant, ?string $code, float $subtotal, ?Contact $contact = null, bool $throw = true): array
    {
        $empty = [
            'valid' => false,
            'coupon_id' => null,
            'code' => null,
            'type' => null,
            'value' => 0.0,
            'amount' => 0.0,
            'minimum_amount' => 0.0,
            'message' => 'Aucun coupon appliqué.',
            'capped' => false,
        ];

        $code = Str::upper(trim((string) $code));
        if ($code === '') {
            return $empty;
        }

        $coupon = Coupon::where('tenant_id', $tenant->id)->where('code', $code)->first();
        $fail = function (string $message) use ($empty, $code, $throw): array {
            if ($throw) {
                throw new \RuntimeException($message);
            }

            return array_merge($empty, ['code' => $code, 'message' => $message]);
        };

        if (! $coupon) {
            return $fail('Coupon introuvable.');
        }

        if (! $coupon->is_active) {
            return $fail('Ce coupon est désactivé.');
        }

        if ($coupon->expires_at && $coupon->expires_at->isPast()) {
            return $fail('Ce coupon est expiré.');
        }

        if ($coupon->max_uses !== null && $coupon->uses_count >= $coupon->max_uses) {
            return $fail('Ce coupon a atteint sa limite d’utilisation.');
        }

        if ($coupon->contact_id && (int) $coupon->contact_id !== (int) $contact?->id) {
            return $fail('Ce coupon est réservé à un autre client.');
        }

        if ((float) $coupon->minimum_amount > $subtotal) {
            return $fail('Montant minimum requis: '.$this->money($coupon->minimum_amount).'.');
        }

        $detail = $coupon->type === 'percent'
            ? $this->discountForSubtotal($subtotal, 'percentage', (float) $coupon->value)
            : $this->discountForSubtotal($subtotal, 'fixed', (float) $coupon->value);

        return [
            'valid' => true,
            'coupon_id' => $coupon->id,
            'code' => $coupon->code,
            'name' => $coupon->name ?: $coupon->code,
            'type' => $coupon->type,
            'value' => (float) $coupon->value,
            'amount' => $detail['amount'],
            'minimum_amount' => (float) $coupon->minimum_amount,
            'message' => 'Coupon '.$coupon->code.' appliqué: '.$this->money($detail['amount']).'.',
            'capped' => $detail['capped'],
        ];
    }

    private function nextSaleNumber(Tenant $tenant): string
    {
        return $this->numbers->next($tenant, 'sale', 'BL', fn ($n) => Sale::where('tenant_id', $tenant->id)->where('number', $n)->exists())['number'];
    }

    private function peekSaleNumber(Tenant $tenant): string
    {
        return $this->numbers->peek($tenant, 'sale', 'BL');
    }

    private function nextTicketNumber(Tenant $tenant): string
    {
        return $this->numbers->next($tenant, 'ticket', 'ATT')['number'];
    }

    private function nextPaymentNumber(Tenant $tenant): string
    {
        return $this->numbers->next($tenant, 'payment', 'PAY')['number'];
    }

    private function nextReturnNumber(Tenant $tenant): string
    {
        return $this->numbers->next($tenant, 'return', 'RET')['number'];
    }

    private function nextDeliveryNumber(Tenant $tenant): string
    {
        return $this->numbers->next($tenant, 'delivery', 'LIV')['number'];
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

    private function onlineOrderAllowedStatuses(OnlineOrder $onlineOrder): array
    {
        return match ($onlineOrder->status) {
            'pending' => ['pending', 'confirmed', 'cancelled'],
            'confirmed' => ['confirmed', 'preparing', 'ready', 'cancelled'],
            'preparing' => ['preparing', 'ready', 'cancelled'],
            'ready' => ['ready', 'cancelled'],
            'fulfilled' => ['fulfilled'],
            'cancelled' => ['cancelled'],
            default => [$onlineOrder->status],
        };
    }

    private function onlineOrderAllowedPaymentStatuses(OnlineOrder $onlineOrder): array
    {
        return match ($onlineOrder->payment_status) {
            'unpaid' => ['unpaid', 'deposit', 'paid'],
            'deposit' => ['deposit', 'paid', 'refunded'],
            'paid' => ['paid', 'refunded'],
            'refunded' => ['refunded'],
            default => [$onlineOrder->payment_status],
        };
    }

    private function onlineOrderCanCreateSale(OnlineOrder $onlineOrder): bool
    {
        return ! $onlineOrder->converted_sale_id
            && in_array($onlineOrder->status, ['confirmed', 'preparing', 'ready'], true);
    }

    private function onlineOrderSaleBlockReason(OnlineOrder $onlineOrder): string
    {
        if ($onlineOrder->converted_sale_id) {
            return 'Une vente existe déjà pour cette précommande.';
        }

        return match ($onlineOrder->status) {
            'pending' => 'Confirmez d’abord la précommande avant de créer la vente.',
            'fulfilled' => 'Cette précommande est déjà traitée.',
            'cancelled' => 'Une précommande annulée ne peut pas être convertie en vente.',
            default => 'Cette précommande ne peut pas être convertie dans son état actuel.',
        };
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

    private function nextPurchasePaymentNumber(Tenant $tenant): string
    {
        $max = PurchasePayment::where('tenant_id', $tenant->id)
            ->where('number', 'like', 'PAF%')
            ->pluck('number')
            ->map(fn ($number) => (int) preg_replace('/\D+/', '', (string) $number))
            ->max() ?? 0;

        return 'PAF'.str_pad((string) ($max + 1), 5, '0', STR_PAD_LEFT);
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

    private function nextExpenseReference(Tenant $tenant): string
    {
        $max = Expense::where('tenant_id', $tenant->id)
            ->where('reference', 'like', 'DEP-REF-%')
            ->pluck('reference')
            ->map(fn ($ref) => (int) preg_replace('/\D+/', '', (string) $ref))
            ->max() ?? 0;

        return 'DEP-REF-'.str_pad((string) ($max + 1), 5, '0', STR_PAD_LEFT);
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

    private function nextCashRegisterSessionNumber(Tenant $tenant): string
    {
        $max = CashRegisterSession::where('tenant_id', $tenant->id)
            ->where('number', 'like', 'CR%')
            ->pluck('number')
            ->map(fn ($number) => (int) preg_replace('/\D+/', '', (string) $number))
            ->max() ?? 0;

        return 'CR'.str_pad((string) ($max + 1), 5, '0', STR_PAD_LEFT);
    }

    private function nextCashRegisterMovementNumber(Tenant $tenant): string
    {
        $max = CashRegisterMovement::where('tenant_id', $tenant->id)
            ->where('number', 'like', 'CRM%')
            ->pluck('number')
            ->map(fn ($number) => (int) preg_replace('/\D+/', '', (string) $number))
            ->max() ?? 0;

        return 'CRM'.str_pad((string) ($max + 1), 6, '0', STR_PAD_LEFT);
    }

    private function openCashRegisterSession(Tenant $tenant, bool $lock = false, ?string $storeKey = null): ?CashRegisterSession
    {
        return app(CashRegisterService::class)->openSession($tenant, $lock, $storeKey ?? $this->currentStore($tenant)['key']);
    }

    private function recordCashRegisterMovement(Tenant $tenant, CashRegisterSession $session, string $type, string $direction, float $amount, array $data = []): CashRegisterMovement
    {
        return app(CashRegisterService::class)->recordMovement($tenant, $session, $type, $direction, $amount, $data);
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

    private function recordStockMovement(Tenant $tenant, Item $item, string $type, int $delta, ?string $referenceType = null, ?int $referenceId = null, ?string $note = null): void
    {
        $this->recordStockMovementSnapshot($tenant, $item->id, $type, $delta, (int) $item->stock_quantity, $referenceType, $referenceId, $note);
    }

    private function recordStockMovementSnapshot(Tenant $tenant, int $itemId, string $type, int $delta, int $quantityAfter, ?string $referenceType = null, ?int $referenceId = null, ?string $note = null): void
    {
        DB::table('stock_movements')->insert([
            'tenant_id' => $tenant->id,
            'item_id' => $itemId,
            'user_id' => auth()->id(),
            'type' => $type,
            'quantity_delta' => $delta,
            'quantity_after' => $quantityAfter,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'note' => $note,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function locationAverageCost(int $tenantId, int $itemId, ?int $variantId, int $locationId): float
    {
        return (float) ItemLocationStock::query()
            ->where('tenant_id', $tenantId)
            ->where('item_id', $itemId)
            ->where('variant_id', $variantId)
            ->where('location_id', $locationId)
            ->value('average_cost') ?? 0;
    }

    private function updateLocationCost(int $tenantId, int $itemId, ?int $variantId, int $locationId, float $unitCost, ?int $receivedQuantity = null): void
    {
        $stock = ItemLocationStock::query()
            ->where('tenant_id', $tenantId)
            ->where('item_id', $itemId)
            ->where('variant_id', $variantId)
            ->where('location_id', $locationId)
            ->lockForUpdate()
            ->first();

        if (! $stock) {
            return;
        }

        $updates = [
            'last_purchase_cost' => $unitCost,
            'updated_at' => now(),
        ];

        if ($receivedQuantity !== null && $receivedQuantity > 0) {
            $oldQuantity = max(0, (int) $stock->quantity - $receivedQuantity);
            $oldAverage = (float) $stock->average_cost;
            $newTotalCost = ($oldQuantity * $oldAverage) + ($receivedQuantity * $unitCost);
            $newQuantity = $oldQuantity + $receivedQuantity;
            $updates['average_cost'] = $newQuantity > 0 ? round($newTotalCost / $newQuantity, 4) : $unitCost;
        }

        $stock->update($updates);
    }

    private function idempotencyKey(Request $request): string
    {
        $key = trim((string) $request->input('_idempotency_key'));

        return $key !== '' ? $key : (string) Str::uuid();
    }

    private function invoiceCanCreateSale(Invoice $invoice): bool
    {
        return $invoice->archived_at === null
            && in_array($invoice->status, ['sent', 'viewed', 'partially_paid', 'paid', 'overdue'], true);
    }

    private function invoiceCreateSaleBlockReason(Invoice $invoice): string
    {
        if ($invoice->archived_at !== null) {
            return 'Cette facture est archivée. Restaurez-la avant de créer une vente.';
        }

        return match ($invoice->status) {
            'draft' => 'Cette facture est encore en brouillon. Marquez-la comme envoyée avant de créer une vente.',
            'cancelled' => 'Cette facture est annulée et ne peut pas créer de vente.',
            default => 'Cette facture ne peut pas créer de vente avec son statut actuel.',
        };
    }

    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelClass
     */
    private function findByIdempotencyKey(string $modelClass, int $tenantId, string $key): ?\Illuminate\Database\Eloquent\Model
    {
        if ($key === '') {
            return null;
        }

        return $modelClass::query()
            ->where('tenant_id', $tenantId)
            ->where('idempotency_key', $key)
            ->first();
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

    private function nextStocktakeNumber(Tenant $tenant): string
    {
        $max = Stocktake::where('tenant_id', $tenant->id)
            ->where('number', 'like', 'INV%')
            ->pluck('number')
            ->map(fn ($number) => (int) preg_replace('/\D+/', '', (string) $number))
            ->max() ?? 0;

        return 'INV'.str_pad((string) ($max + 1), 5, '0', STR_PAD_LEFT);
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

        // Include soft-deleted contacts — their codes still occupy the unique index slot.
        $max = Contact::withTrashed()
            ->where('tenant_id', $tenant->id)
            ->where('kind', $kind)
            ->where('code', 'like', $prefix.'%')
            ->pluck('code')
            ->map(fn ($code) => (int) preg_replace('/\D+/', '', (string) $code))
            ->max() ?? 0;

        $next = $max + 1;
        // Skip past any remaining collisions (e.g. codes imported out of sequence).
        while (Contact::withTrashed()->where('tenant_id', $tenant->id)->where('code', $prefix.str_pad((string) $next, 5, '0', STR_PAD_LEFT))->exists()) {
            $next++;
        }

        return $prefix.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }

    private function salesListQuery(Tenant $tenant, Request $request): Builder
    {
        $query = trim((string) $request->query('q'));
        $detailSale = (int) $request->query('detail_sale');
        $detailInvoice = (int) $request->query('invoice');
        $from = $request->query('from');
        $to = $request->query('to');
        $client = $request->query('client');
        $paymentStatus = $request->query('payment_status');
        $paymentMethod = $request->query('payment_method');
        $minTotal = $request->query('min_total');
        $maxTotal = $request->query('max_total');

        return Sale::query()
            ->with(['contact', 'items', 'payments', 'deliveryOrders', 'returns', 'user', 'invoice.user', 'sourceInvoice.creator', 'sourceOnlineOrder'])
            ->where('tenant_id', $tenant->id)
            ->when($detailSale > 0, fn (Builder $builder) => $builder->whereKey($detailSale))
            ->when($detailInvoice > 0, fn (Builder $builder) => $builder->where(function (Builder $builder) use ($detailInvoice): void {
                $builder->whereHas('invoice', fn (Builder $invoice) => $invoice->whereKey($detailInvoice))
                    ->orWhere('source_invoice_id', $detailInvoice);
            }))
            ->when($query !== '', function (Builder $builder) use ($query): void {
                $builder->where(function (Builder $builder) use ($query): void {
                    $builder->where('number', 'like', "%{$query}%")
                        ->orWhere('payment_method', 'like', "%{$query}%")
                        ->orWhere('metadata->reference_number', 'like', "%{$query}%")
                        ->orWhereHas('sourceOnlineOrder', fn (Builder $orderQuery) => $orderQuery->where('number', 'like', "%{$query}%"))
                        ->orWhereHas('invoice', fn (Builder $invoiceQuery) => $invoiceQuery->where('number', 'like', "%{$query}%"))
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
            ->when(in_array($paymentStatus, ['paid', 'partial', 'unpaid', 'partially_refunded', 'refunded', 'cancelled'], true), function (Builder $builder) use ($paymentStatus): void {
                if ($paymentStatus === 'paid') {
                    $builder->where('status', 'paid');
                } elseif ($paymentStatus === 'unpaid') {
                    $builder->where('status', 'unpaid');
                } elseif ($paymentStatus === 'partially_refunded') {
                    $builder->where('status', 'partially_refunded');
                } elseif ($paymentStatus === 'refunded') {
                    $builder->where('status', 'refunded');
                } elseif ($paymentStatus === 'cancelled') {
                    $builder->where('status', 'cancelled');
                } else {
                    $builder->where('status', 'partial');
                }
            })
            ->latest('sold_at')
            ->latest('id');
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
        $detailReturn = (int) $request->query('detail_return');

        return SaleReturn::query()
            ->with(['sale', 'contact'])
            ->where('tenant_id', $tenant->id)
            ->when($detailReturn > 0, fn (Builder $builder) => $builder->whereKey($detailReturn))
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

    private function onlineOrdersQuery(Tenant $tenant, Request $request): Builder
    {
        $query = trim((string) $request->query('q'));

        return OnlineOrder::query()
            ->with(['contact', 'user', 'items'])
            ->where('tenant_id', $tenant->id)
            ->when($query !== '', function (Builder $builder) use ($query): void {
                $builder->where(function (Builder $builder) use ($query): void {
                    $builder->where('number', 'like', "%{$query}%")
                        ->orWhere('customer_name', 'like', "%{$query}%")
                        ->orWhere('customer_phone', 'like', "%{$query}%")
                        ->orWhere('customer_email', 'like', "%{$query}%")
                        ->orWhere('channel', 'like', "%{$query}%")
                        ->orWhereHas('contact', fn (Builder $contactQuery) => $contactQuery->where('name', 'like', "%{$query}%"))
                        ->orWhereHas('items', fn (Builder $itemQuery) => $itemQuery->where('name', 'like', "%{$query}%")->orWhere('code', 'like', "%{$query}%"));
                });
            })
            ->when($request->query('order_status'), fn (Builder $builder, $status) => $builder->where('status', $status))
            ->when($request->query('payment_status'), fn (Builder $builder, $status) => $builder->where('payment_status', $status))
            ->when($request->query('channel'), fn (Builder $builder, $channel) => $builder->where('channel', $channel))
            ->when($request->query('from'), fn (Builder $builder, $from) => $builder->whereDate('ordered_at', '>=', $from))
            ->when($request->query('to'), fn (Builder $builder, $to) => $builder->whereDate('ordered_at', '<=', $to))
            ->latest('ordered_at')
            ->latest('id');
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
        $detailPurchase = (int) $request->query('detail_purchase');

        return Purchase::query()
            ->with(['supplier', 'items.item', 'returns', 'payments', 'user'])
            ->where('tenant_id', $tenant->id)
            ->when($detailPurchase > 0, fn (Builder $builder) => $builder->whereKey($detailPurchase))
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
        $detailAdjustment = (int) $request->query('detail_adjustment');

        return StockAdjustment::query()
            ->where('tenant_id', $tenant->id)
            ->when($detailAdjustment > 0, fn (Builder $builder) => $builder->whereKey($detailAdjustment))
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
        $detailTransfer = (int) $request->query('detail_transfer');

        return StockTransfer::query()
            ->where('tenant_id', $tenant->id)
            ->when($detailTransfer > 0, fn (Builder $builder) => $builder->whereKey($detailTransfer))
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

    private function stocktakesQuery(Tenant $tenant, Request $request): Builder
    {
        $query = trim((string) $request->query('q'));
        $detailStocktake = (int) $request->query('detail_stocktake');

        return Stocktake::query()
            ->with(['location', 'user', 'items.item'])
            ->where('tenant_id', $tenant->id)
            ->when($detailStocktake > 0, fn (Builder $builder) => $builder->whereKey($detailStocktake))
            ->when($query !== '', function (Builder $builder) use ($query): void {
                $builder->where(function (Builder $builder) use ($query): void {
                    $builder->where('number', 'like', "%{$query}%")
                        ->orWhere('note', 'like', "%{$query}%");
                });
            })
            ->latest('started_at');
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
        $detailReturn = (int) $request->query('detail_purchase_return');

        return PurchaseReturn::query()
            ->with(['purchase', 'supplier'])
            ->where('tenant_id', $tenant->id)
            ->when($detailReturn > 0, fn (Builder $builder) => $builder->whereKey($detailReturn))
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

    private function cashRegisterContext(Tenant $tenant, Request $request): array
    {
        $storeKey = $this->currentStore($tenant)['key'];
        $query = trim((string) $request->query('q'));
        $movementQuery = CashRegisterMovement::query()
            ->with(['session.openedBy', 'user', 'sale'])
            ->where('tenant_id', $tenant->id)
            ->when($query !== '', function (Builder $builder) use ($query): void {
                $builder->where(function (Builder $builder) use ($query): void {
                    $builder->where('number', 'like', "%{$query}%")
                        ->orWhere('type', 'like', "%{$query}%")
                        ->orWhere('reference', 'like', "%{$query}%")
                        ->orWhere('note', 'like', "%{$query}%")
                        ->orWhereHas('session', fn (Builder $session) => $session->where('number', 'like', "%{$query}%"))
                        ->orWhereHas('sale', fn (Builder $sale) => $sale->where('number', 'like', "%{$query}%"));
                });
            })
            ->when($request->query('movement_type'), fn (Builder $builder, $type) => $builder->where('type', $type))
            ->when($request->query('from'), fn (Builder $builder, $from) => $builder->whereDate('moved_at', '>=', $from))
            ->when($request->query('to'), fn (Builder $builder, $to) => $builder->whereDate('moved_at', '<=', $to))
            ->latest('moved_at')
            ->latest();

        $sessionsQuery = CashRegisterSession::query()
            ->with(['openedBy', 'closedBy', 'account'])
            ->where('tenant_id', $tenant->id)
            ->when($request->query('session_status'), fn (Builder $builder, $status) => $builder->where('status', $status))
            ->latest('opened_at')
            ->latest();

        $todayMovements = CashRegisterMovement::where('tenant_id', $tenant->id)->whereDate('moved_at', now()->toDateString())->get();

        return [
            'openSession' => CashRegisterSession::with(['openedBy', 'account', 'movements' => fn ($query) => $query->latest('moved_at')->take(8)])
                ->where('tenant_id', $tenant->id)
                ->where('store_key', $storeKey)
                ->where('status', 'open')
                ->latest('opened_at')
                ->first(),
            'movements' => $movementQuery->paginate(30, ['*'], 'cash_movements_page')->withQueryString(),
            'sessions' => $sessionsQuery->paginate(12, ['*'], 'cash_sessions_page')->withQueryString(),
            'cashAccounts' => FinancialAccount::where('tenant_id', $tenant->id)->where('type', 'cash')->where('is_active', true)->orderBy('name')->get(),
            'todayIn' => $todayMovements->where('direction', 'in')->sum('amount'),
            'todayOut' => $todayMovements->where('direction', 'out')->sum('amount'),
            'todaySalesCash' => $todayMovements->where('type', 'sale_cash')->sum('amount'),
            'todayCount' => $todayMovements->count(),
            'lastClosed' => CashRegisterSession::where('tenant_id', $tenant->id)->where('status', 'closed')->latest('closed_at')->first(),
        ];
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
            'code' => ['nullable', 'string', 'max:60', Rule::unique('contacts', 'code')->where(fn ($q) => $q->where('tenant_id', $tenant->id)->whereNull('deleted_at'))->ignore($contact?->id)],
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
        if ($sale->relationLoaded('payments')) {
            $paid = (float) $sale->payments->sum('amount');
        } else {
            $paid = (float) $sale->payments()->sum('amount');
        }

        $metadataPaid = data_get($sale->metadata, 'paid_amount');
        if ($metadataPaid !== null) {
            $paid = max($paid, (float) $metadataPaid);
        }

        if ($paid <= 0.001 && $sale->status === 'paid') {
            $paid = (float) $sale->total_amount;
        }

        return min(round($paid, 2), (float) $sale->total_amount);
    }

    private function salePaymentStatus(Sale $sale, float $paidAmount): string
    {
        if (in_array($sale->status, ['refunded', 'cancelled'], true)) {
            return $sale->status;
        }

        if ($paidAmount + 0.001 >= (float) $sale->total_amount) {
            return 'paid';
        }

        return $paidAmount > 0.001 ? 'partial' : 'unpaid';
    }

    private function invoiceStatusForSale(Sale $sale, mixed $dueDate = null): string
    {
        if ($sale->status === 'cancelled') {
            return 'cancelled';
        }

        if ($sale->status === 'refunded') {
            return 'refunded';
        }

        $paid = $this->salePaidAmount($sale);
        if ($paid + 0.001 >= (float) $sale->total_amount) {
            return 'paid';
        }

        if ($dueDate && Carbon::parse($dueDate)->toDateString() < now()->toDateString()) {
            return 'overdue';
        }

        return $paid > 0.001 ? 'partial' : 'unpaid';
    }

    private function syncSaleInvoiceStatus(?Sale $sale): void
    {
        if (! $sale?->invoice) {
            return;
        }

        $sale->invoice->update([
            'status' => $this->invoiceStatusForSale($sale, $sale->invoice->due_date),
        ]);
    }

    private function saleSystemNote(Tenant $tenant, string $saleNumber, string $source, ?Contact $contact, int $lineCount, float $total, string $paymentMethod, string $status, Carbon $soldAt, ?string $reference = null): string
    {
        $sourceLabel = match ($source) {
            'pos' => 'caisse POS',
            'manual_sale' => 'vente manuelle',
            'quotation' => 'conversion devis',
            default => $source,
        };
        $statusLabel = match ($status) {
            'paid' => 'payée',
            'partial' => 'partielle',
            'unpaid' => 'impayée',
            'partially_refunded' => 'retour partiel',
            'refunded' => 'remboursée',
            'cancelled' => 'annulée',
            default => $status,
        };
        $storeName = $this->currentStore($tenant)['name'] ?? $tenant->name;
        $userName = auth()->user()?->name ?? 'Système';
        $clientName = $contact?->name ?? 'Client comptoir';
        $formattedTotal = number_format($total, 2, ',', ' ').' DH';
        $referenceText = $reference ? ' Référence: '.$reference.'.' : '';

        return "Note système: vente {$saleNumber} créée automatiquement depuis {$sourceLabel} le ".$soldAt->format('d/m/Y H:i')." par {$userName}. Client: {$clientName}. {$lineCount} ligne(s), total {$formattedTotal}, paiement {$paymentMethod}, statut {$statusLabel}, magasin {$storeName}.{$referenceText}";
    }

    private function nextSaleInvoiceNumber(Tenant $tenant): string
    {
        $max = SaleInvoice::where('tenant_id', $tenant->id)
            ->where('number', 'like', 'FAC%')
            ->pluck('number')
            ->map(fn ($number) => (int) preg_replace('/\D+/', '', (string) $number))
            ->max() ?? 0;

        return 'FAC'.str_pad((string) ($max + 1), 5, '0', STR_PAD_LEFT);
    }

    private function downloadDocumentPdf(Tenant $tenant, array $document): Response
    {
        $settings = $this->documentSettings($tenant);
        $company = $this->companyProfile($tenant);
        $placeholders = $this->documentPlaceholders($tenant, $company, $document);
        $document['rendered_header'] = $this->renderDocumentTemplate($settings['header_text'], $placeholders);
        $document['rendered_note'] = $this->renderDocumentTemplate((string) ($document['template_note'] ?? ''), $placeholders);
        $document['rendered_footer'] = $this->renderDocumentTemplate($settings['footer_text'], $placeholders);
        $document['rendered_terms'] = $this->renderDocumentTemplate($settings['terms'], $placeholders);
        $company['logo_src'] = $this->documentAssetSource((string) ($company['store_logo'] ?? ''));
        $company['signature_src'] = $this->documentAssetSource((string) ($company['signature'] ?? ''));

        $pdf = Pdf::loadView('librairepro.pdf.document', [
            'tenant' => $tenant,
            'company' => $company,
            'settings' => $settings,
            'document' => $document,
            'money' => fn ($amount) => $this->money($amount),
            'formatDate' => fn ($date) => $date ? Carbon::parse($date)->format('d/m/Y') : '—',
        ])->setPaper('a4')->setOptions([
            'isRemoteEnabled' => true,
            'defaultFont' => 'DejaVu Sans',
        ]);

        return $pdf->download($document['filename']);
    }

    private function processAppIconUpload(\Illuminate\Http\UploadedFile $file): void
    {
        $source = $file->getRealPath();
        $sizes = [32 => 'icon-32x32.png', 192 => 'icon-192x192.png', 512 => 'icon-512x512.png'];
        $iconsDir = public_path('icons');

        if (! is_dir($iconsDir)) {
            mkdir($iconsDir, 0755, true);
        }

        foreach ($sizes as $size => $filename) {
            $this->resizePng($source, $iconsDir.'/'.$filename, $size, $size);
        }
    }

    private function deleteAppIconFiles(): void
    {
        $iconsDir = public_path('icons');
        if (!is_dir($iconsDir)) {
            mkdir($iconsDir, 0755, true);
        }

        foreach ([32, 192, 512] as $size) {
            $this->ensureDefaultAppIconGenerated($size);
        }
    }

    private function resizePng(string $source, string $destination, int $width, int $height): void
    {
        $info = getimagesize($source);
        $mime = $info['mime'] ?? 'image/png';
        $srcWidth = $info[0];
        $srcHeight = $info[1];

        $srcImage = match ($mime) {
            'image/jpeg' => imagecreatefromjpeg($source),
            'image/png' => imagecreatefrompng($source),
            'image/webp' => imagecreatefromwebp($source),
            'image/gif' => imagecreatefromgif($source),
            default => imagecreatefrompng($source),
        };

        $dstImage = imagecreatetruecolor($width, $height);
        imagealphablending($dstImage, false);
        imagesavealpha($dstImage, true);
        $transparent = imagecolorallocatealpha($dstImage, 0, 0, 0, 127);
        imagefill($dstImage, 0, 0, $transparent);

        imagecopyresampled($dstImage, $srcImage, 0, 0, 0, 0, $width, $height, $srcWidth, $srcHeight);
        imagepng($dstImage, $destination, 9);

        imagedestroy($srcImage);
        imagedestroy($dstImage);
    }

    private function companyProfile(Tenant $tenant): array
    {
        return array_merge([
            'store_name' => $tenant->name,
            'store_code' => $tenant->slug,
            'mobile' => '',
            'phone' => $tenant->phone,
            'email' => $tenant->email,
            'gst_no' => $tenant->ice,
            'vat_no' => '',
            'rc' => '',
            'cnss' => '',
            'country' => 'Maroc',
            'state' => '',
            'city' => '',
            'postcode' => '',
            'address' => $tenant->address,
            'store_logo' => '',
            'signature' => '',
            'show_signature' => false,
            'bank_details' => '',
            'sales_invoice_footer_text' => '',
            'invoice_terms' => '',
        ], $tenant->settings['company_profile'] ?? []);
    }

    private function documentSettings(Tenant $tenant): array
    {
        $company = $this->companyProfile($tenant);

        return array_merge([
            'sale_title' => 'Bon de vente',
            'invoice_title' => 'Facture',
            'purchase_title' => 'Bon d’achat',
            'primary_color' => data_get($tenant->settings, 'theme.primary', '#3157D5'),
            'accent_color' => data_get($tenant->settings, 'theme.accent', '#0F9F8A'),
            'header_text' => 'Document généré par {{store_name}} le {{today}}.',
            'sale_note_template' => 'Merci pour votre achat {{client_name}}. Ticket {{document_number}}.',
            'invoice_note_template' => 'Facture {{document_number}} liée à la vente {{sale_number}}. Total: {{total}}.',
            'purchase_note_template' => 'Commande fournisseur {{document_number}}. Référence: {{reference}}.',
            'footer_text' => $company['sales_invoice_footer_text'] ?: 'Merci pour votre confiance.',
            'terms' => $company['invoice_terms'] ?: 'Les marchandises restent la propriété du magasin jusqu’au paiement complet.',
            'show_logo' => true,
            'show_signature' => (bool) ($company['show_signature'] ?? false),
            'show_bank_details' => filled($company['bank_details'] ?? null),
        ], $tenant->settings['documents'] ?? []);
    }

    private function documentPlaceholders(Tenant $tenant, array $company, array $document): array
    {
        $partner = $document['partner'] ?? null;

        return [
            'store_name' => $company['store_name'] ?? $tenant->name,
            'store_phone' => $company['phone'] ?: ($company['mobile'] ?? $tenant->phone),
            'store_email' => $company['email'] ?? $tenant->email,
            'store_address' => $company['address'] ?? $tenant->address,
            'ice' => $company['gst_no'] ?? $tenant->ice,
            'document_title' => $document['title'] ?? '',
            'document_number' => $document['number'] ?? '',
            'document_date' => ! empty($document['date']) ? Carbon::parse($document['date'])->format('d/m/Y') : '',
            'due_date' => ! empty($document['due_date']) ? Carbon::parse($document['due_date'])->format('d/m/Y') : '',
            'client_name' => $partner?->name ?? 'Client Grand Public',
            'supplier_name' => $partner?->name ?? 'Fournisseur',
            'partner_name' => $partner?->name ?? '—',
            'partner_phone' => $partner?->phone ?? '',
            'reference' => $document['reference'] ?: '—',
            'sale_number' => $document['type'] === 'invoice' ? data_get($document, 'sale_number', '') : ($document['number'] ?? ''),
            'payment_method' => $document['payment_method'] ?: '—',
            'status' => $document['status'] ?? '',
            'created_by' => $document['created_by'] ?? '',
            'updated_by' => $document['updated_by'] ?? '',
            'total' => $this->money((float) data_get($document, 'totals.total', 0)),
            'today' => now()->format('d/m/Y'),
        ];
    }

    private function renderDocumentTemplate(string $template, array $placeholders): string
    {
        return Str::of($template)->replaceMatches('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', function (array $matches) use ($placeholders): string {
            return (string) ($placeholders[$matches[1]] ?? $matches[0]);
        })->toString();
    }

    private function documentAssetSource(string $path): ?string
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }

        if (Str::startsWith($path, ['http://', 'https://'])) {
            return $path;
        }

        $path = Str::after($path, 'storage/');
        $publicStorage = public_path('storage/'.$path);
        if (is_file($publicStorage)) {
            return $publicStorage;
        }

        $publicPath = public_path($path);
        if (is_file($publicPath)) {
            return $publicPath;
        }

        return null;
    }

    private function tenant(): Tenant
    {
        return TenantContext::require(request(), auth()->user());
    }

    private function demoMaintenanceActions(): array
    {
        return [
            'clear_sales' => 'clear_sales',
            'clear_inventory' => 'clear_inventory',
            'clear_items' => 'clear_items',
            'clear_online_orders' => 'clear_online_orders',
            'clear_purchases' => 'clear_purchases',
            'clear_documents' => 'clear_documents',
            'clear_finance' => 'clear_finance',
            'clear_contacts' => 'clear_contacts',
            'reset_demo' => 'reset_demo',
        ];
    }

    private function demoMaintenanceStats(Tenant $tenant): array
    {
        $inventoryRows = StockAdjustment::where('tenant_id', $tenant->id)->count()
            + StockTransfer::where('tenant_id', $tenant->id)->count()
            + Stocktake::where('tenant_id', $tenant->id)->count()
            + ItemLocationStock::where('tenant_id', $tenant->id)->count()
            + DB::table('stock_movements')->where('tenant_id', $tenant->id)->count();

        $financeRows = Expense::where('tenant_id', $tenant->id)->count()
            + CustomerAdvance::where('tenant_id', $tenant->id)->count()
            + Coupon::where('tenant_id', $tenant->id)->count()
            + DiscountRule::where('tenant_id', $tenant->id)->count()
            + AccountTransaction::where('tenant_id', $tenant->id)->count()
            + CashRegisterSession::where('tenant_id', $tenant->id)->count()
            + CashRegisterMovement::where('tenant_id', $tenant->id)->count();

        $stats = [
            'sales' => Sale::where('tenant_id', $tenant->id)->count(),
            'online_orders' => OnlineOrder::where('tenant_id', $tenant->id)->withTrashed()->count(),
            'purchases' => Purchase::where('tenant_id', $tenant->id)->count(),
            'invoices' => Invoice::where('tenant_id', $tenant->id)->withTrashed()->count() + SaleInvoice::where('tenant_id', $tenant->id)->count(),
            'estimates' => Estimate::where('tenant_id', $tenant->id)->withTrashed()->count() + Quotation::where('tenant_id', $tenant->id)->count(),
            'items' => Item::where('tenant_id', $tenant->id)->count(),
            'contacts' => Contact::where('tenant_id', $tenant->id)->count(),
            'inventory_rows' => $inventoryRows,
            'finance_rows' => $financeRows,
        ];

        $stats['total_demo_rows'] = array_sum($stats);

        return $stats;
    }

    private function executeDemoMaintenanceAction(Tenant $tenant, string $action, bool $hardDelete = false): array
    {
        return match ($action) {
            'clear_sales'         => $this->clearDemoSales($tenant, $hardDelete),
            'clear_inventory'     => $this->clearDemoInventory($tenant, $hardDelete),
            'clear_items'         => $this->clearDemoItems($tenant, $hardDelete),
            'clear_online_orders' => $this->clearDemoOnlineOrders($tenant),
            'clear_purchases'     => $this->clearDemoPurchases($tenant),
            'clear_documents'     => $this->clearDemoDocuments($tenant),
            'clear_finance'       => $this->clearDemoFinance($tenant),
            'clear_contacts'      => $this->clearDemoContacts($tenant, $hardDelete),
            'reset_demo'          => $this->resetDemoWorkspace($tenant, $hardDelete),
            default               => [],
        };
    }

    private function resetDemoWorkspace(Tenant $tenant, bool $hardDelete = false): array
    {
        return $this->mergeCleanupCounts(
            $this->clearDemoSales($tenant, $hardDelete),
            $this->clearDemoOnlineOrders($tenant),
            $this->clearDemoDocuments($tenant),
            $this->clearDemoPurchases($tenant),
            $this->clearDemoFinance($tenant),
            $this->clearDemoItems($tenant, $hardDelete),
            $this->clearDemoContacts($tenant, $hardDelete),
        );
    }

    private function clearDemoSales(Tenant $tenant, bool $hardDelete = false): array
    {
        $saleQuery = fn () => Sale::where('tenant_id', $tenant->id);
        $saleIds = $saleQuery()->pluck('id');

        $counts = [
            'pos_tickets'              => PosTicket::where('tenant_id', $tenant->id)->delete(),
            'cash_register_movements'  => $saleIds->isEmpty() ? 0 : CashRegisterMovement::where('tenant_id', $tenant->id)->whereIn('sale_id', $saleIds)->delete(),
            'sale_stock_movements'     => DB::table('stock_movements')->where('tenant_id', $tenant->id)->where('reference_type', Sale::class)->delete(),
            'sales'                    => $hardDelete ? $saleQuery()->forceDelete() : $saleQuery()->delete(),
        ];

        OnlineOrder::withTrashed()->where('tenant_id', $tenant->id)->update(['converted_sale_id' => null, 'converted_by' => null]);
        Quotation::where('tenant_id', $tenant->id)->update(['converted_sale_id' => null]);

        return $counts;
    }

    private function clearDemoInventory(Tenant $tenant, bool $hardDelete = false): array
    {
        $stocktakeIds = Stocktake::where('tenant_id', $tenant->id)->pluck('id');

        return [
            'stocktake_items'     => $stocktakeIds->isEmpty() ? 0 : StocktakeItem::where('tenant_id', $tenant->id)->whereIn('stocktake_id', $stocktakeIds)->delete(),
            'stocktakes'          => Stocktake::where('tenant_id', $tenant->id)->delete(),
            'stock_adjustments'   => StockAdjustment::where('tenant_id', $tenant->id)->delete(),
            'stock_transfers'     => StockTransfer::where('tenant_id', $tenant->id)->delete(),
            'stock_movements'     => DB::table('stock_movements')->where('tenant_id', $tenant->id)->delete(),
            'item_location_stock' => $hardDelete
                ? ItemLocationStock::withTrashed()->where('tenant_id', $tenant->id)->forceDelete()
                : ItemLocationStock::where('tenant_id', $tenant->id)->delete(),
            'item_stock_reset'    => Item::where('tenant_id', $tenant->id)->update(['stock_quantity' => 0, 'updated_at' => now()]),
            'variant_stock_reset' => DB::table('item_variants')->whereIn('item_id', Item::where('tenant_id', $tenant->id)->select('id'))->update(['stock_quantity' => 0, 'updated_at' => now()]),
        ];
    }

    private function clearDemoItems(Tenant $tenant, bool $hardDelete = false): array
    {
        $counts = $this->mergeCleanupCounts(
            $this->clearDemoSales($tenant, $hardDelete),
            $this->clearDemoOnlineOrders($tenant),
            $this->clearDemoDocuments($tenant),
            $this->clearDemoPurchases($tenant),
            $this->clearDemoFinance($tenant),
            $this->clearDemoInventory($tenant, $hardDelete),
        );

        $itemIds = $hardDelete
            ? Item::withTrashed()->where('tenant_id', $tenant->id)->pluck('id')
            : Item::where('tenant_id', $tenant->id)->pluck('id');

        $counts['loans']         = Loan::where('tenant_id', $tenant->id)->delete();
        $counts['item_variants'] = $itemIds->isEmpty() ? 0 : ItemVariant::whereIn('item_id', $itemIds)->delete();
        $counts['items']         = $hardDelete
            ? Item::withTrashed()->where('tenant_id', $tenant->id)->forceDelete()
            : Item::where('tenant_id', $tenant->id)->delete();

        return $counts;
    }

    private function clearDemoOnlineOrders(Tenant $tenant): array
    {
        $orderIds = OnlineOrder::withTrashed()->where('tenant_id', $tenant->id)->pluck('id');
        Sale::where('tenant_id', $tenant->id)->whereNotNull('source_online_order_id')->update(['source_online_order_id' => null]);

        return [
            'online_order_items' => $orderIds->isEmpty() ? 0 : OnlineOrderItem::whereIn('online_order_id', $orderIds)->delete(),
            'online_orders'      => OnlineOrder::withTrashed()->where('tenant_id', $tenant->id)->forceDelete(),
        ];
    }

    private function clearDemoPurchases(Tenant $tenant): array
    {
        return [
            'purchase_stock_movements' => DB::table('stock_movements')->where('tenant_id', $tenant->id)->where('reference_type', Purchase::class)->delete(),
            'purchase_payments'        => PurchasePayment::where('tenant_id', $tenant->id)->delete(),
            'purchase_returns'         => PurchaseReturn::where('tenant_id', $tenant->id)->delete(),
            'purchases'                => Purchase::where('tenant_id', $tenant->id)->delete(),
        ];
    }

    private function clearDemoDocuments(Tenant $tenant): array
    {
        $invoiceIds  = Invoice::withTrashed()->where('tenant_id', $tenant->id)->pluck('id');
        $estimateIds = Estimate::withTrashed()->where('tenant_id', $tenant->id)->pluck('id');

        Sale::where('tenant_id', $tenant->id)->whereNotNull('source_invoice_id')->update(['source_invoice_id' => null]);
        Estimate::withTrashed()->where('tenant_id', $tenant->id)->update(['converted_invoice_id' => null]);

        return [
            'invoice_histories'  => $invoiceIds->isEmpty() ? 0 : DB::table('document_status_histories')->where('tenant_id', $tenant->id)->where('document_type', 'invoice')->whereIn('document_id', $invoiceIds)->delete(),
            'estimate_histories' => $estimateIds->isEmpty() ? 0 : DB::table('document_status_histories')->where('tenant_id', $tenant->id)->where('document_type', 'estimate')->whereIn('document_id', $estimateIds)->delete(),
            'sale_invoices'      => SaleInvoice::where('tenant_id', $tenant->id)->delete(),
            'invoices'           => Invoice::withTrashed()->where('tenant_id', $tenant->id)->forceDelete(),
            'estimates'          => Estimate::withTrashed()->where('tenant_id', $tenant->id)->forceDelete(),
            'quotations'         => Quotation::where('tenant_id', $tenant->id)->delete(),
            'document_sequences' => DB::table('document_sequences')->where('tenant_id', $tenant->id)->delete(),
        ];
    }

    private function clearDemoFinance(Tenant $tenant): array
    {
        $counts = [
            'cash_register_movements' => CashRegisterMovement::where('tenant_id', $tenant->id)->delete(),
            'cash_register_sessions'  => CashRegisterSession::where('tenant_id', $tenant->id)->delete(),
            'account_transactions'    => AccountTransaction::where('tenant_id', $tenant->id)->delete(),
            'customer_advances'       => CustomerAdvance::where('tenant_id', $tenant->id)->delete(),
            'expenses'                => Expense::where('tenant_id', $tenant->id)->delete(),
            'expense_categories'      => ExpenseCategory::where('tenant_id', $tenant->id)->delete(),
            'coupon_sale'             => DB::table('coupon_sale')->where('tenant_id', $tenant->id)->delete(),
            'discount_rule_sale'      => DB::table('discount_rule_sale')->where('tenant_id', $tenant->id)->delete(),
            'coupons'                 => Coupon::where('tenant_id', $tenant->id)->delete(),
            'discount_rules'          => DiscountRule::where('tenant_id', $tenant->id)->delete(),
            'account_balance_reset'   => FinancialAccount::where('tenant_id', $tenant->id)->update(['current_balance' => DB::raw('opening_balance'), 'updated_at' => now()]),
        ];

        Contact::where('tenant_id', $tenant->id)->update(['advance_balance' => 0, 'outstanding_balance' => 0, 'updated_at' => now()]);

        return $counts;
    }

    private function clearDemoContacts(Tenant $tenant, bool $hardDelete = false): array
    {
        $counts = $this->mergeCleanupCounts(
            $this->clearDemoSales($tenant, $hardDelete),
            $this->clearDemoOnlineOrders($tenant),
            $this->clearDemoDocuments($tenant),
            $this->clearDemoPurchases($tenant),
            $this->clearDemoFinance($tenant),
        );

        $counts['loans']    = Loan::where('tenant_id', $tenant->id)->delete();
        $counts['contacts'] = $hardDelete
            ? Contact::withTrashed()->where('tenant_id', $tenant->id)->forceDelete()
            : Contact::where('tenant_id', $tenant->id)->delete();

        return $counts;
    }

    private function mergeCleanupCounts(array ...$groups): array
    {
        $merged = [];

        foreach ($groups as $group) {
            foreach ($group as $key => $count) {
                $merged[$key] = ($merged[$key] ?? 0) + (int) $count;
            }
        }

        return $merged;
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
            'pin' => ['nullable', 'string', new FourDigitPin],
            'clear_pin' => ['nullable', 'boolean'],
            'avatar_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'profile_photo' => ['nullable', 'image', 'max:2048'],
            'remove_profile_photo' => ['nullable', 'boolean'],
            'role' => ['required', Rule::in($roleKeys)],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::in($permissionKeys)],
            'store_access' => ['nullable', 'array'],
            'store_access.*' => ['string', 'max:120'],
        ]);

        $data['permissions'] ??= [];
        $data['store_access'] ??= [];

        if ((! empty($data['pin']) || $request->boolean('clear_pin')) && ! $this->currentUserIsOwner($tenant)) {
            abort(403, 'Seul le propriétaire peut définir ou réinitialiser un PIN.');
        }

        return $data;
    }

    private function currentUserIsOwner(Tenant $tenant): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        $tenantUser = $tenant->users()->whereKey($user->id)->first();

        return (string) ($tenantUser?->pivot?->role ?? '') === 'owner';
    }

    private function ensurePinUnique(Tenant $tenant, string $pin, mixed $excludeUser = null): void
    {
        $query = $tenant->users()->whereNotNull('users.pin_hash');

        if ($excludeUser) {
            $query->where('users.id', '!=', $excludeUser->id);
        }

        $otherUsers = $query->get();

        foreach ($otherUsers as $other) {
            if (Hash::check($pin, $other->pin_hash)) {
                throw ValidationException::withMessages([
                    'pin' => 'Ce PIN est déjà utilisé par un autre utilisateur.',
                ]);
            }
        }
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
            'sales.edit' => 'Ventes: modifier',
            'sales.delete' => 'Ventes: annuler',
            'sales.refund' => 'Ventes: rembourser',
            'sales.payments' => 'Ventes: paiements',
            'online_orders.view' => 'Précommandes: voir',
            'online_orders.create' => 'Précommandes: créer',
            'online_orders.edit' => 'Précommandes: changer statut',
            'invoices.view' => 'Factures: voir',
            'invoices.create' => 'Factures: créer',
            'invoices.edit_draft' => 'Factures: modifier brouillon',
            'invoices.edit_sent' => 'Factures: modifier envoyée',
            'invoices.send' => 'Factures: envoyer',
            'invoices.payments' => 'Factures: encaisser',
            'invoices.cancel' => 'Factures: annuler',
            'invoices.archive' => 'Factures: archiver',
            'invoices.restore' => 'Factures: restaurer',
            'invoices.duplicate' => 'Factures: dupliquer',
            'estimates.view' => 'Devis: voir',
            'estimates.create' => 'Devis: créer',
            'estimates.edit' => 'Devis: modifier',
            'estimates.send' => 'Devis: envoyer',
            'estimates.accept_decline' => 'Devis: accepter/refuser',
            'estimates.convert' => 'Devis: convertir en facture',
            'estimates.cancel' => 'Devis: annuler',
            'estimates.archive' => 'Devis: archiver',
            'estimates.duplicate' => 'Devis: dupliquer',
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

    private function messagingConfig(Tenant $tenant): array
    {
        return array_merge([
            'default_channel' => 'whatsapp',
            'sender_name' => $tenant->name,
            'reply_to' => $tenant->email,
            'sms_provider' => 'local',
            'sms_sender_id' => null,
            'sms_api_key' => null,
            'whatsapp_provider' => 'whatsapp_business',
            'whatsapp_number' => $tenant->phone,
            'whatsapp_token' => null,
            'email_provider' => 'smtp',
            'email_from' => $tenant->email,
            'test_mode' => true,
            'log_messages' => true,
        ], data_get($tenant->settings, 'messaging', []));
    }

    private function messageTemplates(Tenant $tenant): array
    {
        $defaults = [
            ['key' => 'receipt', 'name' => 'Ticket / reçu', 'channel' => 'whatsapp', 'subject' => 'Votre ticket {{store_name}}', 'body' => "Bonjour {{client_name}},\nVotre achat chez {{store_name}} a bien été enregistré.\nMerci pour votre confiance.", 'is_active' => true],
            ['key' => 'overdue', 'name' => 'Emprunt en retard', 'channel' => 'sms', 'subject' => null, 'body' => 'Bonjour {{client_name}}, votre emprunt chez {{store_name}} est en retard. Merci de passer à la librairie.', 'is_active' => true],
            ['key' => 'school-season', 'name' => 'Rentrée scolaire', 'channel' => 'whatsapp', 'subject' => 'Rentrée scolaire', 'body' => 'Bonjour {{client_name}}, les nouveautés rentrée sont disponibles chez {{store_name}}.', 'is_active' => true],
        ];

        return collect(data_get($tenant->settings, 'message_templates', $defaults))
            ->map(function ($template): array {
                $template = is_array($template) ? $template : [];
                $name = trim((string) ($template['name'] ?? 'Modèle'));

                return [
                    'key' => (string) ($template['key'] ?? Str::slug($name)),
                    'name' => $name,
                    'channel' => in_array($template['channel'] ?? 'whatsapp', ['sms', 'whatsapp', 'email'], true) ? $template['channel'] : 'whatsapp',
                    'subject' => $template['subject'] ?? null,
                    'body' => (string) ($template['body'] ?? ''),
                    'is_active' => (bool) ($template['is_active'] ?? true),
                ];
            })
            ->filter(fn (array $template) => $template['name'] !== '' && $template['body'] !== '')
            ->unique('key')
            ->values()
            ->all();
    }

    private function validateMessageTemplate(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'channel' => ['required', 'in:sms,whatsapp,email'],
            'subject' => ['nullable', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:1600'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }

    private function recipientForChannel(?Contact $contact, string $channel): string
    {
        if (! $contact) {
            return '';
        }

        return match ($channel) {
            'email' => trim((string) $contact->email),
            default => trim((string) $contact->phone),
        };
    }

    private function renderMessageBody(string $body, Tenant $tenant, ?Contact $contact = null): string
    {
        $tokens = [
            '{{store_name}}' => $tenant->name,
            '{{client_name}}' => $contact?->name ?? 'Client',
            '{{client_phone}}' => $contact?->phone ?? '',
            '{{date}}' => now()->format('d/m/Y'),
        ];

        return strtr($body, $tokens);
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

        foreach (['price', 'purchase_price', 'sale_price', 'reseller_sale_price', 'mrp', 'discount'] as $moneyField) {
            if ($request->has($moneyField)) {
                $request->merge([$moneyField => $this->normalizeMoneyInput($request->input($moneyField))]);
            }
        }

        $data = $request->validate([
            'type' => ['required', 'in:book,supply,service'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'item_code' => array_filter([
                'nullable',
                'string',
                'max:120',
                // Only validate uniqueness on edit (creation lets the server regenerate on collision).
                $item ? Rule::unique('items', 'item_code')
                    ->where(fn ($query) => $query->where('tenant_id', $tenant->id))
                    ->ignore($item->id) : null,
            ]),
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
            'tags' => ['nullable', 'string', 'max:1000'],
            'paper_type' => ['nullable', 'string', 'max:255'],
            'cover_type' => ['nullable', 'string', 'max:255'],
            'collection' => ['nullable', 'string', 'max:255'],
            'delivery_note' => ['nullable', 'string', 'max:255'],
            'invoice_reference' => ['nullable', 'string', 'max:255'],
            'seller_points' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'category_id' => ['nullable', 'exists:categories,id'],
            'brand_id' => ['nullable', 'exists:brands,id'],
            'unit_id' => ['required', 'exists:units,id'],
            'tax_id' => ['required', 'exists:taxes,id'],
            'discount_type' => ['nullable', 'in:Percentage,Fixed'],
            'discount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999999'],
            'price' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999'],
            'tax_type' => ['nullable', 'in:Inclusive,Exclusive'],
            'profit_margin' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'purchase_price' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999'],
            'sale_price' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999'],
            'reseller_sale_price' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999'],
            'mrp' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999'],
            'warehouse' => ['nullable', 'string', 'max:255'],
            'opening_stock' => ['nullable', 'integer', 'min:0'],
            'stock_quantity' => ['required', 'integer', 'min:0'],
            'min_stock_threshold' => ['required', 'integer', 'min:0'],
            'location' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:active,archived,out_of_stock'],
            'is_enabled' => ['nullable', 'boolean'],
            'checkout_visible' => ['nullable', 'boolean'],
            'online_store_visible' => ['nullable', 'boolean'],
            'remove_item_image' => ['nullable', 'boolean'],
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

        // Always regenerate if blank; also regenerate if the submitted code is already taken
        // (handles stale page-load suggestions that got claimed between load and submit).
        $submittedCode = ($data['item_code'] ?? null) ?: null;
        if ($submittedCode && ! $item && Item::where('tenant_id', $tenant->id)->where('item_code', $submittedCode)->exists()) {
            $submittedCode = null;
        }
        $data['item_code'] = $submittedCode ?? $this->nextItemCode($tenant->id, $item?->id);
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
        $data['is_enabled'] = $request->boolean('is_enabled', true);
        $data['checkout_visible'] = $request->boolean('checkout_visible', true);
        $data['online_store_visible'] = $request->boolean('online_store_visible', true);
        $data['tags'] = $this->normalizeTagsInput($request->input('tags'));

        if ($request->hasFile('item_image')) {
            $path = $request->file('item_image')->store('catalogue/items', 'public');
            $data['images'] = array_values(array_filter(array_merge([$path], $item?->images ?? [])));
        } elseif ($request->boolean('remove_item_image')) {
            $data['images'] = [];
        } elseif ($item?->images) {
            $data['images'] = $item->images;
        }
        unset($data['item_image'], $data['remove_item_image']);

        if ($data['type'] === 'service') {
            $data['stock_quantity'] = 9999;
            $data['min_stock_threshold'] = 0;
            $data['purchase_price'] = $data['purchase_price'] ?? 0;
            $data['status'] = ($data['status'] ?? 'active') === 'archived' ? 'archived' : 'active';
        }

        return $data;
    }

    private function normalizeTagsInput(mixed $value): array
    {
        $parts = is_array($value)
            ? $value
            : (preg_split('/[,;\\n]+/', (string) $value) ?: []);

        return collect($parts)
            ->map(fn ($tag) => trim((string) $tag))
            ->filter()
            ->map(fn (string $tag) => Str::of($tag)->squish()->limit(40, '')->toString())
            ->unique(fn (string $tag) => mb_strtolower($tag))
            ->values()
            ->take(20)
            ->all();
    }

    private function normalizeMoneyInput(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $raw = trim((string) $value);

        if ($raw === '') {
            return null;
        }

        $normalized = str_replace(["\xc2\xa0", ' ', "'"], '', $raw);
        $lastComma = strrpos($normalized, ',');
        $lastDot = strrpos($normalized, '.');

        if ($lastComma !== false && $lastDot !== false) {
            $decimalSeparator = $lastComma > $lastDot ? ',' : '.';
            $thousandsSeparator = $decimalSeparator === ',' ? '.' : ',';
            $normalized = str_replace($thousandsSeparator, '', $normalized);
            $normalized = str_replace($decimalSeparator, '.', $normalized);
        } elseif ($lastComma !== false) {
            $normalized = str_replace(',', '.', $normalized);
        }

        $normalized = preg_replace('/[^0-9.\-]/', '', $normalized) ?: '0';
        $negative = str_starts_with($normalized, '-');
        $normalized = str_replace('-', '', $normalized);

        if (substr_count($normalized, '.') > 1) {
            $lastDot = strrpos($normalized, '.');
            $normalized = str_replace('.', '', substr($normalized, 0, $lastDot)).substr($normalized, $lastDot);
        }

        $number = (float) ($negative ? '-'.$normalized : $normalized);

        return number_format($number, 2, '.', '');
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

    private function importContactRows(Tenant $tenant, array $rows, string $kind): RedirectResponse
    {
        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $code = trim((string) $this->rowValue($row, $kind === 'supplier'
                ? ['id_du_fournisseur', 'id_fournisseur', 'supplier_id', 'supplier_code', 'code']
                : ['n_de_client', 'numero_client', 'n_client', 'customer_id', 'customer_code', 'code']
            ));
            $name = trim((string) $this->rowValue($row, $kind === 'supplier'
                ? ['nom_du_fournisseur', 'nom_fournisseur', 'supplier_name', 'fournisseur', 'name', 'nom']
                : ['nom_du_client', 'nom_client', 'customer_name', 'client', 'name', 'nom']
            ));

            if ($name === '' || Str::lower($name) === 'total') {
                $skipped++;
                continue;
            }

            $location = trim((string) $this->rowValue($row, ['emplacement', 'location', 'ville', 'city']));
            $status = $this->contactStatus($this->rowValue($row, ['statut', 'status']));
            $email = trim((string) $this->rowValue($row, ['email', 'e_mail', 'courriel'])) ?: null;
            $phone = trim((string) $this->rowValue($row, ['mobile', 'telephone', 'phone', 'tel'])) ?: null;
            $previousDue = $this->decimalValue($this->rowValue($row, ['echeance_precedente', 'solde_precedent', 'previous_balance', 'opening_balance']));

            if ($kind === 'supplier') {
                $purchaseDue = $this->decimalValue($this->rowValue($row, ['achat_du', 'purchase_due']));
                $purchaseReturnDue = $this->decimalValue($this->rowValue($row, ['retour_d_achat_du', 'purchase_return_due']));
                $payload = [
                    'tenant_id' => $tenant->id,
                    'kind' => 'supplier',
                    'code' => $code !== '' ? $code : $this->nextContactCode($tenant, 'supplier'),
                    'name' => $name,
                    'client_type' => 'company',
                    'status' => $status,
                    'phone' => $phone,
                    'email' => $email,
                    'opening_balance' => $previousDue,
                    'outstanding_balance' => $purchaseDue,
                    'advance_balance' => $purchaseReturnDue,
                    'fine_balance' => 0,
                    'credit_limit' => 0,
                    'price_level_type' => 'increase',
                    'price_level' => 0,
                ];
            } else {
                $salesReturnDue = $this->decimalValue($this->rowValue($row, ['retour_des_ventes_du', 'sales_return_due']));
                $advance = $this->decimalValue($this->rowValue($row, ['avance', 'advance', 'advance_balance']));
                $creditLimitRaw = $this->rowValue($row, ['limite_de_credit', 'credit_limit']);
                $creditLimit = Str::contains(Str::lower((string) $creditLimitRaw), ['aucune', 'no limit']) ? 0 : $this->decimalValue($creditLimitRaw);

                $payload = [
                    'tenant_id' => $tenant->id,
                    'kind' => 'client',
                    'code' => $code !== '' ? $code : $this->nextContactCode($tenant, 'client'),
                    'name' => $name,
                    'client_type' => 'individual',
                    'status' => $status,
                    'phone' => $phone,
                    'email' => $email,
                    'city' => $location ?: null,
                    'credit_limit' => $creditLimit,
                    'opening_balance' => $previousDue,
                    'outstanding_balance' => $previousDue,
                    'advance_balance' => $advance + $salesReturnDue,
                    'fine_balance' => 0,
                    'price_level_type' => 'increase',
                    'price_level' => 0,
                ];
            }

            $match = ['tenant_id' => $tenant->id, 'kind' => $kind];
            if ($code !== '') {
                $match['code'] = $code;
            } elseif ($email) {
                $match['email'] = $email;
            } else {
                $match['name'] = $name;
            }

            $contact = Contact::updateOrCreate($match, $payload);
            $contact->wasRecentlyCreated ? $created++ : $updated++;
        }

        $label = $kind === 'supplier' ? 'Fournisseurs' : 'Clients';
        $section = $kind === 'supplier' ? 'suppliers' : 'customers';

        return redirect()
            ->route('module', ['module' => 'contacts', 'section' => $section])
            ->with('status', "{$label}: {$created} créé(s), {$updated} mis à jour, {$skipped} ignoré(s).");
    }

    /**
     * @return array{title: string, filename: string, headers: array<int, string>, rows: array<int, array<int, string|int|float>>}
     */
    private function contactImportExampleRows(string $kind): array
    {
        if ($kind === 'supplier') {
            return [
                'title' => 'Liste des fournisseurs',
                'filename' => 'exemple-import-fournisseurs.xlsx',
                'headers' => ['ID du fournisseur', 'Nom du fournisseur', 'Mobile', 'Email', 'Solde précédent', 'Achat dû', "Retour d'achat dû", 'Total(+)', 'Statut'],
                'rows' => [
                    ['FR0001', 'FOURNISSEUR EXEMPLE', '+212600000000', 'fournisseur@example.test', 0, 1200, 0, 1200, 'Active'],
                    ['FR0002', 'PAPETERIE GROSSISTE', '+212611111111', '', 250, 0, 50, 200, 'Active'],
                ],
            ];
        }

        return [
            'title' => 'Liste des clients',
            'filename' => 'exemple-import-clients.xlsx',
            'headers' => ['N ° de client', 'Nom du client', 'Mobile', 'Email', 'Emplacement', 'Limite de crédit', 'Échéance précédente', 'Retour des ventes dû(+)', 'Avance', 'Statut'],
            'rows' => [
                ['CL0001', 'CLIENT EXEMPLE', '+212600000000', 'client@example.test', 'Casablanca', 'Aucune limite', 150, 0, 20, 'Active'],
                ['CL0002', 'ÉCOLE ATLAS', '+212611111111', '', 'Rabat', 5000, 0, 0, 0, 'Active'],
            ],
        ];
    }

    private function cleanLegacyCategoryName(string $name): string
    {
        $name = preg_replace('/\[(ITEM|SERVICE)\]\s*$/i', '', trim($name)) ?: $name;

        return trim($name) ?: 'Import';
    }

    private function decimalValue(mixed $value): float
    {
        return (float) ($this->normalizeMoneyInput($value) ?? 0);
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

        if (str_contains($status, 'inactive') || str_contains($status, 'inactif') || str_contains($status, 'archive')) {
            return false;
        }

        return $status === '' || str_contains($status, 'active');
    }

    private function contactStatus(mixed $status): string
    {
        return $this->legacyActive($status) ? 'active' : 'archived';
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

    private function importedItemType(array $row, string $kind): string
    {
        if ($kind === 'services') {
            return 'service';
        }

        $rawType = Str::lower(Str::ascii((string) ($this->rowValue($row, [
            'type',
            'item_type',
            'type_d_element',
            'type_element',
            'element_type',
            'type_d_article',
            'type_article',
            'article_type',
        ]) ?: '')));

        $categoryType = Str::lower(Str::ascii((string) $this->rowValue($row, [
            'categorie_type_d_element',
            'category_type_d_element',
            'category',
            'categorie',
            'category_name',
            'nom_categorie',
        ])));

        if ($rawType !== '') {
            if (str_contains($rawType, 'service') || str_contains($rawType, 'prestation') || str_contains($rawType, 'non physique')) {
                return 'service';
            }

            if (str_contains($rawType, 'book') || str_contains($rawType, 'livre')) {
                return 'book';
            }

            if (str_contains($rawType, 'article') || str_contains($rawType, 'item') || str_contains($rawType, 'product') || str_contains($rawType, 'produit') || str_contains($rawType, 'supply') || str_contains($rawType, 'fourniture')) {
                return 'supply';
            }
        }

        $combined = $categoryType;

        if (preg_match('/\[(service|prestation)\]/i', $categoryType) || str_contains($combined, 'service') || str_contains($combined, 'prestation') || str_contains($combined, 'non physique')) {
            return 'service';
        }

        if (preg_match('/\[(item|article|product|produit)\]/i', $categoryType) || str_contains($combined, 'article') || str_contains($combined, 'item') || str_contains($combined, 'product') || str_contains($combined, 'produit') || str_contains($combined, 'supply') || str_contains($combined, 'fourniture')) {
            return 'supply';
        }

        if (str_contains($combined, 'book') || str_contains($combined, 'livre')) {
            return 'book';
        }

        return $this->rowValue($row, ['isbn']) ? 'book' : 'supply';
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
                'headers' => ['Code de barre', "Nom de l'article", "Catégorie/Type d'élément", 'Unité', 'Prix de vente', 'Impôt', 'Statut', 'Tags', "Type d'élément"],
                'rows' => [
                    ['', 'Photocopie A4 noir et blanc', 'Services[SERVICE]', 'Service', '0.50', 'Sans TVA(0.00%)', 'Active', 'impression, rapide', 'Service'],
                    ['', 'Adhésion annuelle', 'Services[SERVICE]', 'Service', '100.00', 'Sans TVA(0.00%)', 'Active', 'adhésion, bibliothèque', 'Service'],
                ],
            ],
            default => [
                'title' => "Liste d'articles",
                'filename' => 'exemple-import-articles.xlsx',
                'headers' => ['Code de barre', "Nom de l'article", "Catégorie/Type d'élément", 'Unité', 'Stock', "Quantité d'alerte", 'Prix de vente', 'Impôt', 'Statut', 'Tags', 'Action', "Type d'élément"],
                'rows' => [
                    ['9780000000001', 'Cahier 96 pages grand format', 'FOURNITURE SCOLAIRE[ITEM]', 'Pièce', '50', '5', '12.00', 'Sans TVA(0.00%)', 'Active', 'rentrée, scolaire', '', 'Article'],
                    ['9780000000002', 'Roman exemple relié', 'ROMANS[ITEM]', 'Pièce', '8', '2', '85.00', 'TVA 7%(7.00%)', 'Active', 'roman, lecture', '', 'Livre'],
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

        $xmlDeclaration = '<'.'?xml version="1.0" encoding="UTF-8" standalone="yes"?'.'>';
        $zip->addFromString('[Content_Types].xml', $xmlDeclaration.'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels', $xmlDeclaration.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', $xmlDeclaration.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', $xmlDeclaration.'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Import" sheetId="1" r:id="rId1"/></sheets></workbook>');
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

        return '<'.'?xml version="1.0" encoding="UTF-8" standalone="yes"?'.'><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.implode('', $xmlRows).'</sheetData></worksheet>';
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

    private function purchaseStatusBadge(?string $status): string
    {
        $labels = [
            'draft' => 'Brouillon',
            'ordered' => 'Commandé',
            'partially_received' => 'Partiel',
            'received' => 'Reçu',
            'cancelled' => 'Annulé',
        ];
        $tones = [
            'draft' => 'bg-slate-100 text-slate-700 ring-slate-200 dark:bg-white/10 dark:text-slate-200 dark:ring-white/10',
            'ordered' => 'bg-blue-50 text-blue-700 ring-blue-200 dark:bg-blue-500/10 dark:text-blue-200 dark:ring-blue-500/20',
            'partially_received' => 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/20',
            'received' => 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-200 dark:ring-emerald-500/20',
            'cancelled' => 'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-200 dark:ring-rose-500/20',
        ];
        $status = $status ?: 'draft';

        return '<span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset '.($tones[$status] ?? $tones['draft']).'">'.e($labels[$status] ?? $status).'</span>';
    }

    private function purchaseActionMenu(Purchase $purchase): string
    {
        $detailUrl = route('module', ['module' => 'purchases', 'section' => 'list', 'detail_purchase' => $purchase->id]);
        $pdfUrl = route('purchases.pdf', $purchase);
        $receiveUrl = route('purchases.receive', $purchase);

        $receiveAction = $purchase->status !== 'received' && $purchase->status !== 'cancelled'
            ? '<form action="'.e($receiveUrl).'" method="POST"><input type="hidden" name="_token" value="'.e(csrf_token()).'"><button type="submit"><span class="sale-action-icon">RC</span><span>Recevoir le stock</span></button></form>'
            : '<button type="button" disabled><span class="sale-action-icon">RC</span><span>Réception terminée</span></button>';

        return '<details class="sale-action-menu" data-sale-action-menu>'
            .'<summary>Action</summary>'
            .'<div class="sale-action-panel">'
            .'<a href="'.e($detailUrl).'"><span class="sale-action-icon">VO</span><span>Voir détail</span></a>'
            .'<a href="'.e($pdfUrl).'"><span class="sale-action-icon">PDF</span><span>Télécharger PDF</span></a>'
            .$receiveAction
            .'</div>'
            .'</details>';
    }

    private function saleStockUnavailableMessage(?Item $item, int $available, int $requested, string $locationName): string
    {
        $name = $item?->title ?: 'cet article';
        $code = $item ? collect([$item->item_code, $item->barcode, $item->isbn])->filter()->first() : null;
        $codeText = $code ? ' ('.$code.')' : '';

        return sprintf(
            'Stock insuffisant pour %s%s dans %s: disponible %d, demandé %d. Ajustez la quantité, ajoutez du stock, ou activez "Autoriser la vente hors stock" dans Paramètres > Magasin si vous voulez accepter un stock négatif.',
            $name,
            $codeText,
            $locationName,
            max(0, $available),
            $requested,
        );
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
