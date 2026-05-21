<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['item_id', 'name', 'attributes', 'barcode', 'purchase_price', 'sale_price', 'stock_quantity'])]
class ItemVariant extends Model
{
    protected function casts(): array
    {
        return [
            'attributes' => 'array',
        ];
    }
}
