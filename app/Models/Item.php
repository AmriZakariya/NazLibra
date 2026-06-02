<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'tenant_id',
    'category_id',
    'brand_id',
    'unit_id',
    'tax_id',
    'type',
    'status',
    'is_enabled',
    'checkout_visible',
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
])]
class Item extends Model
{
    protected function casts(): array
    {
        return [
            'images' => 'array',
            'metadata' => 'array',
            'is_enabled' => 'boolean',
            'checkout_visible' => 'boolean',
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

    public function getIsLowStockAttribute(): bool
    {
        return $this->stock_quantity <= $this->min_stock_threshold;
    }
}
