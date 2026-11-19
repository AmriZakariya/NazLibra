<?php

namespace App\Support;

use App\Models\Item;
use Illuminate\Support\Facades\DB;

/**
 * « Consommation »: the products the services used over a period, by product
 * and by service, in the product's content unit AND in stocked units, with
 * their cost.
 *
 * Read from what each sale line snapshotted (sale_item_consumptions), net of
 * what refunds gave back, so a later change of recipe or capacity never
 * rewrites last month.
 */
final class ConsumptionReport
{
    /**
     * @param  string  $fromUtc  'Y-m-d H:i:s' UTC (inclusive)
     * @param  string  $toUtc    'Y-m-d H:i:s' UTC (inclusive)
     * @return array{by_product: list<array<string, mixed>>, by_service: list<array<string, mixed>>, totals: array{cost: float, lines: int}}
     */
    public static function compute(int $tenantId, string $fromUtc, string $toUtc): array
    {
        $rows = DB::table('sale_item_consumptions as c')
            ->join('sales', 'sales.id', '=', 'c.sale_id')
            ->where('c.tenant_id', $tenantId)
            ->whereNull('sales.deleted_at')
            ->whereIn('sales.status', ['paid', 'completed', 'refunded', 'partial_refund'])
            ->whereBetween('sales.sold_at', [$fromUtc, $toUtc])
            ->get([
                'c.sale_item_id', 'c.service_item_id', 'c.component_item_id', 'c.content_unit',
                'c.quantity_content', 'c.quantity_stock', 'c.total_cost', 'c.returned_stock',
            ]);

        $names = Item::withTrashed()->where('tenant_id', $tenantId)
            ->whereIn('id', $rows->pluck('component_item_id')->merge($rows->pluck('service_item_id'))->filter()->unique()->all())
            ->pluck('title', 'id');

        // What one stocked unit is called (« flacon »), so « 3,45 » can read « 3,45 flacons ».
        $containers = Item::withTrashed()->where('tenant_id', $tenantId)
            ->whereIn('id', $rows->pluck('component_item_id')->filter()->unique()->all())
            ->pluck('content_container', 'id');

        $products = [];
        $services = [];
        $totalCost = 0.0;

        foreach ($rows as $r) {
            $stock = (float) $r->quantity_stock;
            // The share that stayed consumed once refunds gave theirs back.
            $kept = $stock > 0 ? max(0.0, 1 - (float) $r->returned_stock / $stock) : 1.0;
            $content = (float) $r->quantity_content * $kept;
            $stocked = $stock * $kept;
            $cost = (float) $r->total_cost * $kept;
            $totalCost += $cost;

            $pKey = $r->component_item_id.'|'.($r->content_unit ?? '');
            $products[$pKey] ??= [
                'item_id' => (int) $r->component_item_id,
                'name' => $names[$r->component_item_id] ?? '—',
                'content_unit' => $r->content_unit,
                'content_container' => $containers[$r->component_item_id] ?? null,
                'quantity_content' => 0.0, 'quantity_stock' => 0.0, 'cost' => 0.0, 'lines' => [],
            ];
            $products[$pKey]['quantity_content'] += $content;
            $products[$pKey]['quantity_stock'] += $stocked;
            $products[$pKey]['cost'] += $cost;
            $products[$pKey]['lines'][$r->sale_item_id] = true;

            $sKey = (string) $r->service_item_id;
            $services[$sKey] ??= [
                'item_id' => $r->service_item_id !== null ? (int) $r->service_item_id : null,
                'name' => $names[$r->service_item_id] ?? '—',
                'cost' => 0.0, 'lines' => [], 'products' => [],
            ];
            $services[$sKey]['cost'] += $cost;
            $services[$sKey]['lines'][$r->sale_item_id] = true;
            $services[$sKey]['products'][$pKey] ??= [
                'item_id' => (int) $r->component_item_id,
                'name' => $names[$r->component_item_id] ?? '—',
                'content_unit' => $r->content_unit,
                'quantity_content' => 0.0, 'quantity_stock' => 0.0, 'cost' => 0.0,
            ];
            $services[$sKey]['products'][$pKey]['quantity_content'] += $content;
            $services[$sKey]['products'][$pKey]['quantity_stock'] += $stocked;
            $services[$sKey]['products'][$pKey]['cost'] += $cost;
        }

        $round = fn (array $p): array => array_merge($p, [
            'quantity_content' => round($p['quantity_content'], 3),
            'quantity_stock' => round($p['quantity_stock'], 4),
            'cost' => round($p['cost'], 2),
        ]);

        $byProduct = collect($products)->map(function (array $p) use ($round): array {
            $p['lines'] = count($p['lines']);

            return $round($p);
        })->sortByDesc('cost')->values()->all();

        $byService = collect($services)->map(function (array $s) use ($round): array {
            $s['lines'] = count($s['lines']);
            $s['cost'] = round($s['cost'], 2);
            $s['products'] = collect($s['products'])->map($round)->sortByDesc('cost')->values()->all();

            return $s;
        })->sortByDesc('cost')->values()->all();

        return [
            'by_product' => $byProduct,
            'by_service' => $byService,
            'totals' => ['cost' => round($totalCost, 2), 'lines' => $rows->pluck('sale_item_id')->unique()->count()],
        ];
    }
}
