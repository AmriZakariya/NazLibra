<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'sale_id', 'contact_id', 'user_id', 'number', 'status', 'refund_method', 'total_amount', 'lines', 'reason', 'restock', 'returned_at', 'idempotency_key'])]
class SaleReturn extends Model
{
    protected function casts(): array
    {
        return [
            'lines' => 'array',
            'restock' => 'boolean',
            'returned_at' => 'datetime',
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
