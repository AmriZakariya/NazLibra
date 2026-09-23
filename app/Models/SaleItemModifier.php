<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What was actually chosen on a sold line.
 *
 * The name and the price are SNAPSHOTS. A shop renaming "fromage" or changing
 * its supplement next month must not rewrite what a customer was charged for
 * last week.
 */
#[Fillable(['tenant_id', 'sale_item_id', 'modifier_id', 'name', 'price_delta', 'quantity'])]
class SaleItemModifier extends Model
{
    protected function casts(): array
    {
        return [
            'price_delta' => 'decimal:2',
            'quantity' => 'decimal:3',
        ];
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }

    public function modifier(): BelongsTo
    {
        return $this->belongsTo(Modifier::class);
    }
}
