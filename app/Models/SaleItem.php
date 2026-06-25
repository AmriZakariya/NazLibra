<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['sale_id', 'item_id', 'name', 'quantity', 'unit_price', 'total_price', 'unit_cost', 'total_cost'])]
class SaleItem extends Model
{
    use SoftDeletes;

    // When a SaleItem is created/updated, bump the parent Sale's updated_at
    // so the next delta sync picks up the change.
    protected $touches = ['sale'];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
