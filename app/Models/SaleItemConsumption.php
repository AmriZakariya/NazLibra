<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What a sale line consumed, as it was at the sale (see the migration). */
class SaleItemConsumption extends Model
{
    protected $fillable = [
        'tenant_id', 'sale_id', 'sale_item_id', 'service_item_id', 'component_item_id',
        'location_id', 'choice_group', 'content_unit', 'quantity_content', 'quantity_stock',
        'unit_cost', 'total_cost', 'shortfall_stock', 'returned_stock', 'movement_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity_content' => 'decimal:4',
            'quantity_stock' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'total_cost' => 'decimal:4',
            'shortfall_stock' => 'decimal:4',
            'returned_stock' => 'decimal:4',
        ];
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'component_item_id');
    }
}
