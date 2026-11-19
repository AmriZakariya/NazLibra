<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['tenant_id', 'number', 'status', 'warehouse', 'reason', 'total_quantity', 'lines', 'note', 'adjusted_at'])]
class StockAdjustment extends Model
{
    protected function casts(): array
    {
        return [
            'lines' => 'array',
            'total_quantity' => \App\Casts\Quantity::class,
            'adjusted_at' => 'datetime',
        ];
    }
}
