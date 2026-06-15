<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['tenant_id', 'contact_id', 'user_id', 'number', 'status', 'payment_method', 'subtotal_amount', 'discount_amount', 'tax_amount', 'total_amount', 'sold_at', 'metadata', 'idempotency_key'])]
class Sale extends Model
{
    protected function casts(): array
    {
        return [
            'sold_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(SaleInvoice::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(SaleReturn::class);
    }

    public function deliveryOrders(): HasMany
    {
        return $this->hasMany(DeliveryOrder::class);
    }

    public function coupons(): BelongsToMany
    {
        return $this->belongsToMany(Coupon::class, 'coupon_sale')
            ->withPivot('amount_applied')
            ->withTimestamps();
    }

    public function discountRules(): BelongsToMany
    {
        return $this->belongsToMany(DiscountRule::class, 'discount_rule_sale')
            ->withPivot('amount_applied')
            ->withTimestamps();
    }
}
