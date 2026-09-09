<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One dish the kitchen was asked to make.
 *
 * The only mutable field is the status, and it moves in one direction:
 * pending -> cooking -> ready -> served, with cancelled dominant. See
 * [statusRank] and KdsSyncService for why that is a join rather than
 * last-write-wins.
 */
class KdsLine extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id', 'tenant_id', 'fire_id', 'ticket_id', 'station_id',
        'cart_line_id', 'item_id', 'name', 'quantity', 'note', 'status',
        'status_at', 'status_by_user_name', 'cancel_was_started', 'fired_at',
        'origin_device_id', 'revision', 'client_updated_at',
    ];

    protected $casts = [
        'quantity' => 'float',
        'cancel_was_started' => 'boolean',
        'status_at' => 'datetime',
        'fired_at' => 'datetime',
        'client_updated_at' => 'datetime',
        'revision' => 'integer',
    ];

    public const STATUSES = ['pending', 'cooking', 'ready', 'served', 'cancelled'];

    /**
     * Position in the lifecycle.
     *
     * `cancelled` deliberately sits outside the ordering — it is dominant
     * rather than furthest-advanced — so it shares the rank of `pending` and
     * is handled separately everywhere.
     */
    public static function statusRank(?string $status): int
    {
        return match ($status) {
            'cooking' => 1,
            'ready' => 2,
            'served' => 3,
            default => 0,
        };
    }

    public function fire(): BelongsTo
    {
        return $this->belongsTo(KdsFire::class, 'fire_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
