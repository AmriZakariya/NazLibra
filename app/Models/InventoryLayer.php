<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Represents a single batch of incoming stock with a fixed unit cost.
 *
 * Every incoming inventory movement creates one layer.
 * Outgoing movements consume layers in LIFO order (newest first).
 *
 * Inventory value = SUM(remaining_quantity * unit_cost) across all non-exhausted layers.
 *
 * @property int         $id
 * @property int         $tenant_id
 * @property int         $item_id
 * @property int|null    $variant_id
 * @property int         $location_id
 * @property int         $source_movement_id
 * @property float       $original_quantity
 * @property float       $remaining_quantity
 * @property float       $unit_cost
 * @property \Carbon\Carbon $occurred_at
 * @property \Carbon\Carbon|null $exhausted_at
 */
class InventoryLayer extends Model
{
    protected $fillable = [
        'tenant_id',
        'item_id',
        'variant_id',
        'location_id',
        'source_movement_id',
        'original_quantity',
        'remaining_quantity',
        'unit_cost',
        'occurred_at',
        'exhausted_at',
    ];

    protected $casts = [
        'original_quantity'  => 'decimal:4',
        'remaining_quantity' => 'decimal:4',
        'unit_cost'          => 'decimal:4',
        'occurred_at'        => 'datetime',
        'exhausted_at'       => 'datetime',
    ];

    // ── Relations ──────────────────────────────────────────────────────────────

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function sourceMovement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'source_movement_id');
    }

    public function consumptions(): HasMany
    {
        return $this->hasMany(InventoryLayerConsumption::class, 'inventory_layer_id');
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    public function isExhausted(): bool
    {
        return (float) $this->remaining_quantity <= 0;
    }

    /** Current value of the remaining stock in this layer. */
    public function currentValue(): float
    {
        return round((float) $this->remaining_quantity * (float) $this->unit_cost, 4);
    }

    // ── Scopes ─────────────────────────────────────────────────────────────────

    /** Layers that still have stock. */
    public function scopeAvailable($query)
    {
        return $query->where('remaining_quantity', '>', 0);
    }

    /** LIFO order: newest layer consumed first. */
    public function scopeLifoOrder($query)
    {
        return $query->orderByDesc('occurred_at')->orderByDesc('id');
    }

    /** Chronological order for rebuild replay. */
    public function scopeChronological($query)
    {
        return $query->orderBy('occurred_at')->orderBy('id');
    }
}
