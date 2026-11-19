<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'stocktake_id', 'item_id', 'variant_id', 'expected_quantity', 'counted_quantity', 'note'])]
class StocktakeItem extends Model
{
    protected function casts(): array
    {
        return [
            // Whole when whole, a decimal for a product stocked in fractions.
            'expected_quantity' => \App\Casts\Quantity::class,
            'counted_quantity' => \App\Casts\Quantity::class,
        ];
    }

    public function stocktake(): BelongsTo
    {
        return $this->belongsTo(Stocktake::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ItemVariant::class);
    }

    public function difference(): int|float
    {
        return \App\Support\Stock::number(round((float) ($this->counted_quantity ?? 0) - (float) $this->expected_quantity, 4));
    }
}
