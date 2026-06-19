<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'sale_id', 'contact_id', 'user_id', 'number', 'status', 'refund_method', 'refund_scope', 'total_amount', 'lines', 'reason', 'restock', 'stock_disposition', 'returned_at', 'idempotency_key', 'metadata'])]
class SaleReturn extends Model
{
    protected function casts(): array
    {
        return [
            'lines' => 'array',
            'restock' => 'boolean',
            'returned_at' => 'datetime',
            'metadata' => 'array',
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
