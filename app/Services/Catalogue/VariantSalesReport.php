<?php

namespace App\Services\Catalogue;

use App\Models\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What actually sold, broken down by sub-product.
 *
 * The whole point of variants: a shop stocking S, M and L wants to know which
 * sizes move. Two shapes, because they answer different questions —
 * "which of THIS shirt sold" and "which sizes sell across the whole shop".
 */
class VariantSalesReport
{
    /** Sales counted by variant for one article. */
    public function byVariant(Tenant $tenant, int $itemId, ?string $from = null, ?string $to = null): Collection
    {
        return DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->leftJoin('item_variants', 'item_variants.id', '=', 'sale_items.variant_id')
            ->where('sales.tenant_id', $tenant->id)
            ->where('sale_items.item_id', $itemId)
            ->whereNull('sale_items.deleted_at')
            // A cancelled sale is soft-deleted rather than re-statused, so
            // that — not the status column — is what excludes it.
            ->whereNull('sales.deleted_at')
            ->when($from, fn ($q) => $q->whereDate('sales.sold_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('sales.sold_at', '<=', $to))
            ->groupBy('sale_items.variant_id', 'item_variants.name')
            ->select([
                'sale_items.variant_id',
                // A line sold before the article had variants, or a plain
                // article: named rather than left blank, so the total still
                // reconciles with the sales report.
                DB::raw("COALESCE(item_variants.name, 'Sans déclinaison') as variant_name"),
                DB::raw('SUM(sale_items.quantity) as quantity'),
                DB::raw('SUM(sale_items.total_price) as revenue'),
            ])
            ->orderByDesc('quantity')
            ->get();
    }

    /**
     * Sales counted by option value across every article.
     *
     * "How many L did we sell" regardless of which shirt it was — the join
     * the pivot table exists for, and the reason variant options are not left
     * in a free-form JSON blob.
     */
    public function byOptionValue(Tenant $tenant, ?int $optionTypeId = null, ?string $from = null, ?string $to = null): Collection
    {
        return DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('item_variant_values', 'item_variant_values.item_variant_id', '=', 'sale_items.variant_id')
            ->join('option_values', 'option_values.id', '=', 'item_variant_values.option_value_id')
            ->join('option_types', 'option_types.id', '=', 'item_variant_values.option_type_id')
            ->where('sales.tenant_id', $tenant->id)
            ->whereNull('sale_items.deleted_at')
            // A cancelled sale is soft-deleted rather than re-statused, so
            // that — not the status column — is what excludes it.
            ->whereNull('sales.deleted_at')
            ->when($optionTypeId, fn ($q) => $q->where('option_types.id', $optionTypeId))
            ->when($from, fn ($q) => $q->whereDate('sales.sold_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('sales.sold_at', '<=', $to))
            ->groupBy('option_types.id', 'option_types.name', 'option_values.id', 'option_values.value')
            ->select([
                'option_types.name as option_type',
                'option_values.value as option_value',
                DB::raw('SUM(sale_items.quantity) as quantity'),
                DB::raw('SUM(sale_items.total_price) as revenue'),
            ])
            ->orderBy('option_types.name')
            ->orderByDesc('quantity')
            ->get();
    }
}
