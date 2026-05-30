<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['tenant_id', 'number', 'status', 'store_from', 'warehouse_from', 'store_to', 'warehouse_to', 'total_quantity', 'lines', 'note', 'transferred_at'])]
class StockTransfer extends Model
{
    protected function casts(): array
    {
        return [
            'lines' => 'array',
            'transferred_at' => 'datetime',
        ];
    }
}
