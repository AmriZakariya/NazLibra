<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'tenant_id',
    'external_id',
    'category_id',
    'brand_id',
    'unit_id',
    'tax_id',
    'type',
    'status',
    'is_enabled',
    'checkout_visible',
    'online_store_visible',
    'item_code',
    'item_group',
    'nb_item',
    'title',
    'isbn',
    'barcode',
    'sku',
    'custom_barcode1',
    'sac',
    'hsn',
    'author',
    'editor',
    'verifier',
    'translator',
    'edition_year',
    'edition_number',
    'theme',
    'paper_type',
    'cover_type',
    'collection',
    'delivery_note',
    'invoice_reference',
    'seller_points',
    'description',
    'discount_type',
    'discount',
    'price',
    'tax_type',
    'profit_margin',
    'purchase_price',
    'sale_price',
    'reseller_sale_price',
    'mrp',
    'warehouse',
    'opening_stock',
    'stock_quantity',
    'min_stock_threshold',
    'location',
    'images',
    'metadata',
    'tags',
])]
class Item extends Model
{
    use SoftDeletes;
    protected function casts(): array
    {
        return [
            'images' => 'array',
            'metadata' => 'array',
            'tags' => 'array',
            'is_enabled' => 'boolean',
            'checkout_visible' => 'boolean',
            'online_store_visible' => 'boolean',
            'discount' => 'decimal:2',
            'price' => 'decimal:2',
            'purchase_price' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'reseller_sale_price' => 'decimal:2',
            'mrp' => 'decimal:2',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ItemVariant::class);
    }

    public function itemLocationStocks(): HasMany
    {
        return $this->hasMany(ItemLocationStock::class);
    }

    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    public function getIsLowStockAttribute(): bool
    {
        return $this->stock_quantity <= $this->min_stock_threshold;
    }

    public function totalStockQuantity(): int
    {
        return (int) $this->itemLocationStocks()->sum('quantity');
    }

    public function totalAvailableQuantity(): int
    {
        return (int) $this->itemLocationStocks()
            ->selectRaw('SUM(quantity - reserved_quantity) as available')
            ->value('available') ?? 0;
    }
}
