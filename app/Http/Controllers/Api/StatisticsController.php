<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StatisticsController extends Controller
{
    // ── Helpers ───────────────────────────────────────────────────────────────

    private function tenant(Request $request): object
    {
        return $request->attributes->get('api_tenant');
    }

    private function dateRange(Request $request): array
    {
        $tenant   = $this->tenant($request);
        $timezone = $tenant->timezone ?? 'Africa/Casablanca';

        $from = $request->query('from')
            ? Carbon::parse($request->query('from'), $timezone)->startOfDay()->utc()
            : Carbon::now($timezone)->startOfDay()->utc();

        $to = $request->query('to')
            ? Carbon::parse($request->query('to'), $timezone)->endOfDay()->utc()
            : Carbon::now($timezone)->endOfDay()->utc();

        return [$from->toDateTimeString(), $to->toDateTimeString()];
    }

    // ── Overview (extended KPIs) ───────────────────────────────────────────────

    public function overview(Request $request)
    {
        $tenant            = $this->tenant($request);
        [$fromDt, $toDt]   = $this->dateRange($request);

        // Revenue + COGS from confirmed sale lines
        $salesLine = DB::table('sale_items')
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->where('sales.tenant_id', $tenant->id)
            ->whereNull('sales.deleted_at')
            ->whereIn('sales.status', ['paid', 'completed'])
            ->whereBetween('sales.sold_at', [$fromDt, $toDt])
            ->selectRaw('
                COUNT(DISTINCT sales.id)   AS sale_count,
                SUM(sale_items.quantity)   AS items_sold,
                SUM(sale_items.total_price) AS gross_revenue,
                SUM(sale_items.total_cost)  AS cogs
            ')
            ->first();

        // Discount totals from sales header
        $discountTotal = (float) DB::table('sales')
            ->where('tenant_id', $tenant->id)
            ->whereNull('deleted_at')
            ->whereIn('status', ['paid', 'completed'])
            ->whereBetween('sold_at', [$fromDt, $toDt])
            ->sum('discount_amount');

        // Returns — use returned_at (matches when the return was actually processed)
        $returnsRow = DB::table('sale_returns')
            ->join('sales', 'sale_returns.sale_id', '=', 'sales.id')
            ->where('sales.tenant_id', $tenant->id)
            ->whereNull('sales.deleted_at')
            ->whereBetween('sale_returns.returned_at', [$fromDt, $toDt])
            ->selectRaw('COUNT(*) AS cnt, COALESCE(SUM(sale_returns.total_amount), 0) AS total')
            ->first();

        // Cancellations
        $cancelRow = DB::table('sales')
            ->where('tenant_id', $tenant->id)
            ->whereNull('deleted_at')
            ->whereIn('status', ['cancelled', 'refunded'])
            ->whereBetween('sold_at', [$fromDt, $toDt])
            ->selectRaw('COUNT(*) AS cnt, COALESCE(SUM(total_amount), 0) AS amount')
            ->first();

        $gross      = (float) ($salesLine->gross_revenue ?? 0);
        $returns    = (float) ($returnsRow->total ?? 0);
        $cogs       = (float) ($salesLine->cogs ?? 0);
        $net        = max(0.0, $gross - $returns);
        $profit     = $net - $cogs;
        $saleCount  = (int) ($salesLine->sale_count ?? 0);

        return response()->json([
            'ok'   => true,
            'kpis' => [
                'sale_count'      => $saleCount,
                'items_sold'      => (int) ($salesLine->items_sold ?? 0),
                'gross_revenue'   => round($gross, 2),
                'discount_total'  => round($discountTotal, 2),
                'returns_total'   => round($returns, 2),
                'return_count'    => (int) ($returnsRow->cnt ?? 0),
                'net_revenue'     => round($net, 2),
                'cogs'            => round($cogs, 2),
                'gross_profit'    => round($profit, 2),
                'margin_percent'  => $net > 0 ? round($profit / $net * 100, 2) : 0.0,
                'avg_ticket'      => $saleCount > 0 ? round($net / $saleCount, 2) : 0.0,
                'cancel_count'    => (int) ($cancelRow->cnt ?? 0),
                'cancel_amount'   => round((float) ($cancelRow->amount ?? 0), 2),
            ],
        ]);
    }

    // ── By payment method ─────────────────────────────────────────────────────

    public function byPayment(Request $request)
    {
        $tenant           = $this->tenant($request);
        [$fromDt, $toDt]  = $this->dateRange($request);

        $rows = DB::table('sale_payments')
            ->join('sales', 'sale_payments.sale_id', '=', 'sales.id')
            ->where('sales.tenant_id', $tenant->id)
            ->whereNull('sales.deleted_at')
            ->whereIn('sales.status', ['paid', 'completed'])
            ->whereBetween('sales.sold_at', [$fromDt, $toDt])
            ->selectRaw('
                sale_payments.method,
                COUNT(DISTINCT sales.id)    AS cnt,
                SUM(sale_payments.amount)   AS amount
            ')
            ->groupBy('sale_payments.method')
            ->orderByDesc('amount')
            ->get();

        $total = (float) $rows->sum('amount');

        return response()->json([
            'ok'        => true,
            'total'     => round($total, 2),
            'breakdown' => $rows->map(fn ($r) => [
                'method'  => $r->method,
                'amount'  => round((float) $r->amount, 2),
                'count'   => (int) $r->cnt,
                'percent' => $total > 0 ? round((float) $r->amount / $total * 100, 1) : 0.0,
            ])->values(),
        ]);
    }

    // ── By product ────────────────────────────────────────────────────────────

    public function byProduct(Request $request)
    {
        $tenant           = $this->tenant($request);
        [$fromDt, $toDt]  = $this->dateRange($request);
        $limit            = min((int) $request->get('limit', 20), 50);

        $rows = DB::table('sale_items')
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->where('sales.tenant_id', $tenant->id)
            ->whereNull('sales.deleted_at')
            ->whereIn('sales.status', ['paid', 'completed'])
            ->whereBetween('sales.sold_at', [$fromDt, $toDt])
            ->selectRaw('
                sale_items.item_id,
                sale_items.name,
                SUM(sale_items.quantity)    AS qty,
                SUM(sale_items.total_price) AS revenue,
                SUM(sale_items.total_cost)  AS cost,
                COUNT(DISTINCT sales.id)    AS order_count
            ')
            ->groupBy('sale_items.item_id', 'sale_items.name')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get();

        return response()->json([
            'ok'       => true,
            'products' => $rows->map(function ($r) {
                $rev    = (float) $r->revenue;
                $cost   = (float) $r->cost;
                $profit = $rev - $cost;

                return [
                    'item_id'     => $r->item_id,
                    'name'        => $r->name,
                    'qty'         => (int) $r->qty,
                    'revenue'     => round($rev, 2),
                    'cost'        => round($cost, 2),
                    'gross_profit'=> round($profit, 2),
                    'margin'      => $rev > 0 ? round($profit / $rev * 100, 1) : 0.0,
                    'order_count' => (int) $r->order_count,
                ];
            })->values(),
        ]);
    }

    // ── By operator ───────────────────────────────────────────────────────────

    public function byOperator(Request $request)
    {
        $tenant           = $this->tenant($request);
        [$fromDt, $toDt]  = $this->dateRange($request);

        $rows = DB::table('sales')
            ->where('tenant_id', $tenant->id)
            ->whereNull('deleted_at')
            ->whereIn('status', ['paid', 'completed'])
            ->whereBetween('sold_at', [$fromDt, $toDt])
            ->selectRaw("
                user_id,
                COALESCE(actor_name_snapshot, 'Inconnu') AS operator_name,
                COUNT(*) AS sale_count,
                SUM(total_amount)    AS revenue,
                SUM(discount_amount) AS discount_total
            ")
            ->groupBy('user_id', 'actor_name_snapshot')
            ->orderByDesc('revenue')
            ->get();

        return response()->json([
            'ok'        => true,
            'operators' => $rows->map(function ($r) {
                $count = (int) $r->sale_count;
                $rev   = (float) $r->revenue;

                return [
                    'user_id'        => $r->user_id,
                    'operator_name'  => $r->operator_name,
                    'sale_count'     => $count,
                    'revenue'        => round($rev, 2),
                    'discount_total' => round((float) ($r->discount_total ?? 0), 2),
                    'avg_ticket'     => $count > 0 ? round($rev / $count, 2) : 0.0,
                ];
            })->values(),
        ]);
    }

    // ── By category ───────────────────────────────────────────────────────────

    public function byCategory(Request $request)
    {
        $tenant           = $this->tenant($request);
        [$fromDt, $toDt]  = $this->dateRange($request);

        $rows = DB::table('sale_items')
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->leftJoin('items', 'sale_items.item_id', '=', 'items.id')
            ->leftJoin('categories', 'items.category_id', '=', 'categories.id')
            ->where('sales.tenant_id', $tenant->id)
            ->whereNull('sales.deleted_at')
            ->whereIn('sales.status', ['paid', 'completed'])
            ->whereBetween('sales.sold_at', [$fromDt, $toDt])
            ->selectRaw("
                categories.id                                       AS category_id,
                COALESCE(categories.name, 'Sans catégorie')        AS category_name,
                SUM(sale_items.quantity)                            AS qty,
                SUM(sale_items.total_price)                         AS revenue,
                SUM(sale_items.total_cost)                          AS cost
            ")
            ->groupBy('categories.id', 'categories.name')
            ->orderByDesc('revenue')
            ->get();

        return response()->json([
            'ok'         => true,
            'categories' => $rows->map(function ($r) {
                $rev    = (float) $r->revenue;
                $cost   = (float) $r->cost;
                $profit = $rev - $cost;

                return [
                    'category_id'   => $r->category_id,
                    'category_name' => $r->category_name,
                    'qty'           => (int) $r->qty,
                    'revenue'       => round($rev, 2),
                    'cost'          => round($cost, 2),
                    'gross_profit'  => round($profit, 2),
                    'margin'        => $rev > 0 ? round($profit / $rev * 100, 1) : 0.0,
                ];
            })->values(),
        ]);
    }

    // ── By hour (peak-hour heatmap) ───────────────────────────────────────────

    public function byHour(Request $request)
    {
        $tenant           = $this->tenant($request);
        [$fromDt, $toDt]  = $this->dateRange($request);

        $isSqlite = DB::connection()->getDriverName() === 'sqlite';
        $hourExpr = $isSqlite
            ? "CAST(strftime('%H', sold_at) AS INTEGER)"
            : 'HOUR(sold_at)';

        $rows = DB::table('sales')
            ->where('tenant_id', $tenant->id)
            ->whereNull('deleted_at')
            ->whereIn('status', ['paid', 'completed'])
            ->whereBetween('sold_at', [$fromDt, $toDt])
            ->selectRaw("$hourExpr AS hr, COUNT(*) AS cnt, COALESCE(SUM(total_amount), 0) AS revenue")
            ->groupBy('hr')
            ->orderBy('hr')
            ->get();

        $hourMap = $rows->keyBy('hr');
        $hours   = [];
        for ($h = 0; $h < 24; $h++) {
            $row     = $hourMap->get($h);
            $hours[] = [
                'hour'    => $h,
                'count'   => (int) ($row?->cnt ?? 0),
                'revenue' => round((float) ($row?->revenue ?? 0), 2),
            ];
        }

        return response()->json(['ok' => true, 'hours' => $hours]);
    }
}
