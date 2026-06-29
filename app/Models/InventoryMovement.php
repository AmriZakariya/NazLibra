<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class InventoryMovement extends Model
{
    use HasFactory;

    protected $table = 'stock_movements';

    protected $fillable = [
        'tenant_id',
        'item_id',
        'variant_id',
        'location_id',
        'user_id',
        'type',
        'quantity_before',
        'quantity_delta',
        'quantity_after',
        'unit_cost',
        'total_cost',
        'cogs',
        'occurred_at',
        'synced_at',
        'reference_type',
        'reference_id',
        'reference_number',
        'note',
        'reason',
        'idempotency_key',
        'virtual_device_id',
        'actor_name_snapshot',
        'terminal_name_snapshot',
        'real_device_platform',
        'real_device_browser',
        'real_device_ip',
        'real_device_user_agent',
    ];

    public $timestamps = true;

    protected function casts(): array
    {
        return [
            'quantity_before' => 'decimal:4',
            'quantity_delta'  => 'decimal:4',
            'quantity_after'  => 'decimal:4',
            'unit_cost'       => 'decimal:4',
            'total_cost'      => 'decimal:4',
            'cogs'            => 'decimal:4',
            'occurred_at'     => 'datetime',
            'synced_at'       => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ItemVariant::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeForItem($query, int $itemId, ?int $variantId = null)
    {
        $query->where('item_id', $itemId);
        if ($variantId !== null) {
            $query->where('variant_id', $variantId);
        }

        return $query;
    }

    public function scopeAtLocation($query, int $locationId)
    {
        return $query->where('location_id', $locationId);
    }

    public function scopeOfType($query, string|array $type)
    {
        return $query->whereIn('type', (array) $type);
    }
}
