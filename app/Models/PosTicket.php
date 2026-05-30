<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'contact_id', 'user_id', 'converted_sale_id', 'number', 'status', 'cart', 'subtotal_amount', 'discount_amount', 'tax_amount', 'total_amount', 'note', 'held_at'])]
class PosTicket extends Model
{
    protected function casts(): array
    {
        return [
            'cart' => 'array',
            'held_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
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
