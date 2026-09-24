<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id', 'number', 'status', 'source_location_id', 'destination_location_id',
    'store_from', 'warehouse_from', 'store_to', 'warehouse_to', 'total_quantity',
    'lines', 'note', 'created_by', 'transferred_at',
    'sent_at', 'sent_by', 'received_at', 'received_by', 'receipt_note',
    'cancelled_at', 'cancelled_by', 'cancellation_reason',
])]
class StockTransfer extends Model
{
    protected function casts(): array
    {
        return [
            'lines' => 'array',
            'transferred_at' => 'datetime',
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
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

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /** Nothing has moved yet; the paper can still be changed or torn up. */
    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    /** Gone from the source, not yet anywhere else. */
    public function isInTransit(): bool
    {
        return $this->status === 'in_transit';
    }

    /**
     * Arrived.
     *
     * `completed` is the status transfers carried when creating one moved the
     * stock in a single step; it means the same thing here.
     */
    public function isReceived(): bool
    {
        return in_array($this->status, ['received', 'completed'], true);
    }

    /** What is between the two emplacements right now. */
    public function quantityInTransit(): int
    {
        return $this->isInTransit() ? (int) $this->total_quantity : 0;
    }

    /** How much of what was sent actually turned up. */
    public function receivedQuantity(): int
    {
        return (int) collect($this->lines ?? [])
            ->sum(fn (array $line): int => (int) ($line['received_quantity'] ?? 0));
    }

    /** Sent minus received: the part that went missing on the way. */
    public function shortfall(): int
    {
        return $this->isReceived()
            ? max(0, (int) $this->total_quantity - $this->receivedQuantity())
            : 0;
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
        // A brouillon has moved nothing, so there is nothing to send back and
        // no emplacement needed to send it to.
        if ($this->isDraft()) {
            return true;
        }

        return ! $this->isCancelled()
            && $this->source_location_id !== null
            && $this->destination_location_id !== null;
    }

    /** The stage label a shop reads. */
    public function statusLabel(): string
    {
        return match (true) {
            $this->isCancelled() => 'Annulé',
            $this->isDraft() => 'Brouillon',
            $this->isInTransit() => 'En transit',
            $this->shortfall() > 0 => 'Reçu avec écart',
            default => 'Reçu',
        };
    }

    public function statusTone(): string
    {
        return match (true) {
            $this->isCancelled() => 'danger',
            $this->isDraft() => 'neutral',
            $this->isInTransit() => 'info',
            $this->shortfall() > 0 => 'warning',
            default => 'success',
        };
    }
}
