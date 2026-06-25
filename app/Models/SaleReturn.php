<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'sale_id', 'contact_id', 'user_id', 'virtual_device_id', 'actor_name_snapshot', 'terminal_name_snapshot', 'number', 'status', 'refund_method', 'refund_scope', 'total_amount', 'lines', 'reason', 'restock', 'stock_disposition', 'returned_at', 'idempotency_key', 'metadata'])]
class SaleReturn extends Model
{
    use SoftDeletes;

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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function virtualDevice(): BelongsTo
    {
        return $this->belongsTo(VirtualDevice::class);
    }
}
