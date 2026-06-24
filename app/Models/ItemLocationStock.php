<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ItemLocationStock extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'item_location_stock';

    protected $fillable = [
        'tenant_id',
        'item_id',
        'variant_id',
        'location_id',
        'quantity',
        'reserved_quantity',
        'incoming_quantity',
        'damaged_quantity',
        'returned_quantity',
        'transferred_quantity',
        'awaiting_confirmation_quantity',
        'min_stock',
        'max_stock',
        'reorder_point',
        'preferred_stock_level',
        'average_cost',
        'last_purchase_cost',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'reserved_quantity' => 'integer',
            'incoming_quantity' => 'integer',
            'damaged_quantity' => 'integer',
            'returned_quantity' => 'integer',
            'transferred_quantity' => 'integer',
            'awaiting_confirmation_quantity' => 'integer',
            'min_stock' => 'integer',
            'max_stock' => 'integer',
            'reorder_point' => 'integer',
            'preferred_stock_level' => 'integer',
            'average_cost' => 'decimal:4',
            'last_purchase_cost' => 'decimal:4',
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

    public function availableQuantity(): int
    {
        return max(0, $this->quantity - $this->reserved_quantity);
    }

    public function isLowStock(): bool
    {
        return $this->reorder_point > 0 && $this->availableQuantity() <= $this->reorder_point;
    }

    public function isOutOfStock(): bool
    {
        return $this->availableQuantity() <= 0;
    }

    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeAtLocation($query, int $locationId)
    {
        return $query->where('location_id', $locationId);
    }
}
