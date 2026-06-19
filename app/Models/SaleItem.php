<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['sale_id', 'item_id', 'name', 'quantity', 'unit_price', 'total_price', 'unit_cost', 'total_cost'])]
class SaleItem extends Model
{
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
