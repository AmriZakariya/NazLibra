<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'sale_id', 'contact_id', 'user_id', 'number', 'method', 'amount', 'paid_at', 'reference', 'note', 'idempotency_key'])]
class SalePayment extends Model
{
    /** A payment changes the sale representation consumed by delta sync. */
    protected $touches = ['sale'];

    protected function casts(): array
    {
        return [
            'paid_at' => 'datetime',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
