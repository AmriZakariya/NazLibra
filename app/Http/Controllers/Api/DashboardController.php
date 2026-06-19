<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\ItemLocationStock;
use App\Models\Sale;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

/**
 * Mobile dashboard KPIs — always computed server-side so statistics are
 * consistent across devices regardless of what each device has synced.
 */
class DashboardController extends Controller
{
    /**
     * GET /api/v1/dashboard?from=<date>&to=<date>
     *
     * Returns KPIs for the given period (default: today in tenant timezone).
     */
    #[OA\Get(
        path: '/api/v1/dashboard',
        operationId: 'dashboardIndex',
        summary: 'Get POS dashboard KPIs for a date range',
        security: [['bearerAuth' => []]],
        tags: ['Dashboard'],
        parameters: [
            new OA\Parameter(name: 'X-Tenant-Slug', in: 'header', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'X-Location-Id', in: 'header', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'from', in: 'query', required: false, description: 'Start date (defaults to today)', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, description: 'End date (defaults to today)', schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Dashboard KPIs',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'ok', type: 'boolean', example: true),
                    new OA\Property(property: 'period', type: 'object', properties: [
                        new OA\Property(property: 'from', type: 'string', format: 'date'),
                        new OA\Property(property: 'to', type: 'string', format: 'date'),
                    ]),
                    new OA\Property(property: 'kpis', type: 'object', properties: [
                        new OA\Property(property: 'sale_count', type: 'integer'),
                        new OA\Property(property: 'items_sold', type: 'integer'),
                        new OA\Property(property: 'gross_revenue', type: 'number'),
                        new OA\Property(property: 'returns_total', type: 'number'),
                        new OA\Property(property: 'net_revenue', type: 'number'),
                        new OA\Property(property: 'cogs', type: 'number'),
                        new OA\Property(property: 'gross_profit', type: 'number'),
                        new OA\Property(property: 'margin_percent', type: 'number'),
                        new OA\Property(property: 'avg_ticket', type: 'number'),
                    ]),
                    new OA\Property(property: 'stock_health', type: 'object', properties: [
                        new OA\Property(property: 'low_stock', type: 'integer'),
                        new OA\Property(property: 'out_of_stock', type: 'integer'),
                    ]),
                    new OA\Property(property: 'payment_breakdown', type: 'object'),
                    new OA\Property(property: 'top_items', type: 'array', items: new OA\Items(type: 'object')),
                ])
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant   = $request->attributes->get('api_tenant');
        $timezone = $tenant->timezone ?? 'Africa/Casablanca';

        $from = $request->query('from')
            ? Carbon::parse($request->query('from'), $timezone)->startOfDay()->utc()
            : Carbon::now($timezone)->startOfDay()->utc();

        $to = $request->query('to')
            ? Carbon::parse($request->query('to'), $timezone)->endOfDay()->utc()
            : Carbon::now($timezone)->endOfDay()->utc();

        // Revenue and COGS from confirmed sales (use snapshotted unit_cost — never items.purchase_price).
        $salesData = DB::table('sale_items')
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->where('sales.tenant_id', $tenant->id)
            ->where('sales.status', 'paid')
            ->whereBetween('sales.sold_at', [$from, $to])
            ->selectRaw('
                COUNT(DISTINCT sales.id) AS sale_count,
                SUM(sale_items.quantity)  AS items_sold,
                SUM(sale_items.total_price) AS gross_revenue,
                SUM(sale_items.total_cost)  AS cogs
            ')
            ->first();

        $grossRevenue = (float) ($salesData->gross_revenue ?? 0);
        $cogs         = (float) ($salesData->cogs ?? 0);
        $saleCount    = (int) ($salesData->sale_count ?? 0);
        $itemsSold    = (int) ($salesData->items_sold ?? 0);

        // Returns.
        $returnsTotal = (float) DB::table('sale_returns')
            ->join('sales', 'sale_returns.sale_id', '=', 'sales.id')
            ->where('sales.tenant_id', $tenant->id)
            ->whereBetween('sale_returns.created_at', [$from, $to])
            ->sum('sale_returns.total_amount');

        $netRevenue  = max(0, $grossRevenue - $returnsTotal);
        $grossProfit = $netRevenue - $cogs;
        $margin      = $netRevenue > 0 ? round($grossProfit / $netRevenue * 100, 2) : 0;
        $avgTicket   = $saleCount > 0 ? round($netRevenue / $saleCount, 2) : 0;

        // Payment method breakdown.
        $paymentBreakdown = DB::table('sale_payments')
            ->join('sales', 'sale_payments.sale_id', '=', 'sales.id')
            ->where('sales.tenant_id', $tenant->id)
            ->whereBetween('sales.sold_at', [$from, $to])
            ->selectRaw('sale_payments.method, SUM(sale_payments.amount) AS total')
            ->groupBy('sale_payments.method')
            ->pluck('total', 'method');

        // Stock health.
        $lowStockCount    = Item::where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->whereColumn('stock_quantity', '<=', 'min_stock_threshold')
            ->where('min_stock_threshold', '>', 0)
            ->count();

        $outOfStockCount = Item::where('tenant_id', $tenant->id)
            ->where('status', 'out_of_stock')
            ->count();

        // Top 5 items by revenue for the period.
        $topItems = DB::table('sale_items')
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->where('sales.tenant_id', $tenant->id)
            ->where('sales.status', 'paid')
            ->whereBetween('sales.sold_at', [$from, $to])
            ->selectRaw('sale_items.item_id, sale_items.name, SUM(sale_items.quantity) AS qty_sold, SUM(sale_items.total_price) AS revenue, SUM(sale_items.total_cost) AS cost')
            ->groupBy('sale_items.item_id', 'sale_items.name')
            ->orderByDesc('revenue')
            ->limit(5)
            ->get()
            ->map(fn ($row) => [
                'item_id' => $row->item_id,
                'name'    => $row->name,
                'qty'     => (int) $row->qty_sold,
                'revenue' => (float) $row->revenue,
                'cost'    => (float) $row->cost,
                'margin'  => $row->revenue > 0 ? round(($row->revenue - $row->cost) / $row->revenue * 100, 1) : 0,
            ]);

        return response()->json([
            'ok'      => true,
            'period'  => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'kpis'    => [
                'sale_count'       => $saleCount,
                'items_sold'       => $itemsSold,
                'gross_revenue'    => round($grossRevenue, 2),
                'returns_total'    => round($returnsTotal, 2),
                'net_revenue'      => round($netRevenue, 2),
                'cogs'             => round($cogs, 2),
                'gross_profit'     => round($grossProfit, 2),
                'margin_percent'   => $margin,
                'avg_ticket'       => $avgTicket,
            ],
            'stock_health'       => ['low_stock' => $lowStockCount, 'out_of_stock' => $outOfStockCount],
            'payment_breakdown'  => $paymentBreakdown,
            'top_items'          => $topItems,
        ]);
    }
}
