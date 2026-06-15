<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'tenant_id',
    'item_id',
    'name',
    'attributes',
    'barcode',
    'sku',
    'isbn',
    'language',
    'edition',
    'format',
    'publisher',
    'author',
    'purchase_price',
    'sale_price',
    'stock_quantity',
    'min_stock_threshold',
    'status',
    'is_active',
    'image',
    'notes',
    'sort_order',
])]
class ItemVariant extends Model
{
    protected function casts(): array
    {
        return [
            'attributes' => 'array',
            'is_active' => 'boolean',
            'purchase_price' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'stock_quantity' => 'integer',
            'min_stock_threshold' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function itemLocationStocks(): HasMany
    {
        return $this->hasMany(ItemLocationStock::class, 'variant_id');
    }

    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class, 'variant_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeInactive($query)
    {
        return $query->where('is_active', false);
    }

    public function scopeForTenant($query, $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeSearch($query, $search)
    {
        if (empty($search)) {
            return $query;
        }
        return $query->where(function ($q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
              ->orWhere('barcode', 'like', "%{$search}%")
              ->orWhere('sku', 'like', "%{$search}%")
              ->orWhere('isbn', 'like', "%{$search}%")
              ->orWhere('format', 'like', "%{$search}%")
              ->orWhere('language', 'like', "%{$search}%")
              ->orWhere('edition', 'like', "%{$search}%")
              ->orWhere('publisher', 'like', "%{$search}%")
              ->orWhere('author', 'like', "%{$search}%")
              ->orWhereHas('item', function ($q) use ($search) {
                  $q->where('title', 'like', "%{$search}%")
                    ->orWhere('item_code', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%")
                    ->orWhere('isbn', 'like', "%{$search}%");
              });
        });
    }

    public function scopeFilterByProduct($query, $itemId)
    {
        return $query->where('item_id', $itemId);
    }

    public function scopeFilterByStatus($query, $status)
    {
        if ($status === 'all') {
            return $query;
        }
        return $query->where('status', $status);
    }

    public function scopeFilterByStock($query, $stock)
    {
        if ($stock === 'all') {
            return $query;
        }
        if ($stock === 'low') {
            return $query->whereColumn('stock_quantity', '<=', 'min_stock_threshold')->where('stock_quantity', '>', 0);
        }
        if ($stock === 'out') {
            return $query->where('stock_quantity', '<=', 0);
        }
        if ($stock === 'available') {
            return $query->where('stock_quantity', '>', 0);
        }
        return $query;
    }

    public function getIsLowStockAttribute(): bool
    {
        return $this->stock_quantity > 0 && $this->stock_quantity <= $this->min_stock_threshold;
    }

    public function getIsOutOfStockAttribute(): bool
    {
        return $this->stock_quantity <= 0;
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->name ?? 'Sans nom';
    }

    public function getFullNameAttribute(): string
    {
        $itemName = $this->item?->title ?? 'Article inconnu';
        return $itemName . ' — ' . $this->display_name;
    }

    public function getMarginPercentAttribute(): float
    {
        if ($this->purchase_price <= 0) {
            return 0;
        }
        return round((($this->sale_price - $this->purchase_price) / $this->purchase_price) * 100, 2);
    }
}
