<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a service's recipe: a product it consumes.
 *
 * variant_id null = the service itself; a variant row overrides the base row
 * for the same product (quantity 0 removes it) or adds one of its own.
 */
class ItemComponent extends Model
{
    protected $fillable = [
        'tenant_id', 'item_id', 'variant_id', 'component_item_id',
        'quantity', 'choice_group', 'is_default', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'is_default' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'component_item_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ItemVariant::class, 'variant_id');
    }
}
