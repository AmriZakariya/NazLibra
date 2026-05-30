<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'contact_id', 'converted_sale_id', 'number', 'status', 'subtotal_amount', 'discount_amount', 'tax_amount', 'total_amount', 'quoted_at', 'expires_at', 'lines', 'metadata'])]
class Quotation extends Model
{
    protected function casts(): array
    {
        return [
            'quoted_at' => 'datetime',
            'expires_at' => 'date',
            'lines' => 'array',
            'metadata' => 'array',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function convertedSale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'converted_sale_id');
    }
}
