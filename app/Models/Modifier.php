<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One choice on a line: "supplément fromage, +5 DH".
 *
 * It is NOT a sub-product. If it has its own pile on a shelf it is a variant
 * and belongs in item_variants; the difference is what stops a burger with
 * eight toppings becoming 256 rows.
 */
#[Fillable([
    'tenant_id', 'modifier_group_id', 'name', 'price_delta',
    'linked_item_id', 'consumes_quantity', 'sort_order', 'is_active',
])]
class Modifier extends Model
{
    protected function casts(): array
    {
        return [
            'price_delta' => 'decimal:2',
            'consumes_quantity' => 'decimal:3',
            'is_active' => 'boolean',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ModifierGroup::class, 'modifier_group_id');
    }

    /** The article this eats into, for the few that do. */
    public function linkedItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'linked_item_id');
    }

    /** Sold lines that carry this option — what stops a hard delete. */
    public function saleLines(): HasMany
    {
        return $this->hasMany(SaleItemModifier::class);
    }

    public function consumesStock(): bool
    {
        return $this->linked_item_id !== null && (float) $this->consumes_quantity > 0;
    }
}
