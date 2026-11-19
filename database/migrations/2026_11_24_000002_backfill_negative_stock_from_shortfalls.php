<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Until now a service that consumed from an empty shelf left the stock at 0 and
 * only noted the shortfall. The stock now shows its real value: for every
 * item + location sitting at 0 whose consumptions recorded a shortfall with no
 * incoming movement since, the caches become minus those shortfalls and the
 * deficit is recorded so the next purchase settles it.
 *
 * Idempotent: a movement already carrying a deficit is never counted twice.
 * Plain SQL (COALESCE, no IF()) so it runs on SQLite and MySQL. No history is
 * rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sale_item_consumptions')
            || ! Schema::hasColumn('stock_movements', 'deficit_quantity')
            || ! Schema::hasColumn('item_location_stock', 'deficit_quantity')) {
            return;
        }

        $rows = DB::table('sale_item_consumptions as c')
            ->join('stock_movements as m', 'm.id', '=', 'c.movement_id')
            ->where('c.shortfall_stock', '>', 0)
            ->where('m.deficit_quantity', '<=', 0)
            ->select('c.id', 'c.tenant_id', 'c.component_item_id', 'c.location_id', 'c.shortfall_stock',
                'c.movement_id', 'm.cogs', 'm.total_cost')
            ->orderBy('c.id')
            ->get();

        $touched = [];
        foreach ($rows as $row) {
            $stock = DB::table('item_location_stock')
                ->where('tenant_id', $row->tenant_id)
                ->where('item_id', $row->component_item_id)
                ->where('location_id', $row->location_id)
                ->whereNull('variant_id')
                ->first();
            if (! $stock || (float) $stock->quantity > 0.00005) {
                continue; // Only a shelf at 0 (or already below) is corrected.
            }

            $laterIncoming = DB::table('stock_movements')
                ->where('tenant_id', $row->tenant_id)
                ->where('item_id', $row->component_item_id)
                ->where('location_id', $row->location_id)
                ->where('id', '>', $row->movement_id)
                ->where('quantity_delta', '>', 0)
                ->exists();
            if ($laterIncoming) {
                continue;
            }

            $shortfall = round((float) $row->shortfall_stock, 4);
            $fallback = $shortfall > 0
                ? max(0.0, round(((float) $row->total_cost - (float) $row->cogs) / $shortfall, 4))
                : 0.0;

            DB::table('stock_movements')->where('id', $row->movement_id)->update([
                'deficit_quantity' => $shortfall,
                'deficit_unit_cost' => $fallback,
            ]);
            $literal = number_format($shortfall, 4, '.', '');
            DB::table('item_location_stock')->where('id', $stock->id)->update([
                'deficit_quantity' => DB::raw('COALESCE(deficit_quantity, 0) + '.$literal),
                'quantity' => DB::raw('COALESCE(quantity, 0) - '.$literal),
            ]);
            $touched[$row->tenant_id.'-'.$row->component_item_id] = [$row->tenant_id, $row->component_item_id];
        }

        foreach ($touched as [$tenantId, $itemId]) {
            $total = (float) DB::table('item_location_stock')
                ->where('tenant_id', $tenantId)->where('item_id', $itemId)->sum('quantity');
            DB::table('items')->where('tenant_id', $tenantId)->where('id', $itemId)
                ->update(['stock_quantity' => $total]);
        }
    }

    public function down(): void
    {
    }
};
