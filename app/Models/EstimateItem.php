<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'estimate_id', 'tenant_id', 'item_id', 'display_order', 'item_type', 'sku', 'barcode',
    'name', 'description', 'quantity', 'unit', 'unit_price', 'discount_type',
    'discount_value', 'discount_amount', 'tax_rate', 'tax_inclusive', 'tax_amount',
    'subtotal', 'total', 'note', 'item_snapshot',
])]
class EstimateItem extends Model
{
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'discount_value' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_rate' => 'decimal:4',
            'tax_inclusive' => 'boolean',
            'tax_amount' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'total' => 'decimal:2',
            'item_snapshot' => 'array',
        ];
    }

    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
