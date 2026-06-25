<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'purchase_id', 'supplier_id', 'number', 'status', 'total_amount', 'returned_at', 'reason', 'lines', 'metadata', 'idempotency_key'])]
class PurchaseReturn extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'returned_at' => 'datetime',
            'lines' => 'array',
            'metadata' => 'array',
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'supplier_id');
    }
}
