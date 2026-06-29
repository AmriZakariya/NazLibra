<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Records exactly which inventory layer(s) were consumed by an outgoing movement.
 *
 * A single outgoing movement may span multiple layers (e.g. selling 21 units
 * from three different cost layers of 10 each). Each partial consumption
 * of a layer creates one InventoryLayerConsumption row.
 *
 * Aggregate total_cost across all consumptions of a movement = COGS for that movement.
 *
 * @property int   $id
 * @property int   $outgoing_movement_id
 * @property int   $inventory_layer_id
 * @property float $quantity_consumed
 * @property float $unit_cost
 * @property float $total_cost
 */
class InventoryLayerConsumption extends Model
{
    protected $fillable = [
        'outgoing_movement_id',
        'inventory_layer_id',
        'quantity_consumed',
        'unit_cost',
        'total_cost',
    ];

    protected $casts = [
        'quantity_consumed' => 'decimal:4',
        'unit_cost'         => 'decimal:4',
        'total_cost'        => 'decimal:4',
    ];

    // ── Relations ──────────────────────────────────────────────────────────────

    public function outgoingMovement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'outgoing_movement_id');
    }

    public function layer(): BelongsTo
    {
        return $this->belongsTo(InventoryLayer::class, 'inventory_layer_id');
    }
}
