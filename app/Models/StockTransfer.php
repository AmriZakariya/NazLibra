<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id', 'number', 'status', 'source_location_id', 'destination_location_id',
    'store_from', 'warehouse_from', 'store_to', 'warehouse_to', 'total_quantity',
    'lines', 'note', 'created_by', 'transferred_at',
    'cancelled_at', 'cancelled_by', 'cancellation_reason',
])]
class StockTransfer extends Model
{
    protected function casts(): array
    {
        return [
            'lines' => 'array',
            'transferred_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function sourceLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'source_location_id');
    }

    public function destinationLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'destination_location_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    /**
     * Whether the goods can still be sent back automatically.
     *
     * Transfers written before locations were recorded cannot be: there is no
     * way to know which two places to move the stock between.
     */
    public function isReversible(): bool
    {
        return ! $this->isCancelled()
            && $this->source_location_id !== null
            && $this->destination_location_id !== null;
    }
}
