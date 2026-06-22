<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['tenant_id', 'location_id', 'contact_id', 'user_id', 'virtual_device_id', 'actor_name_snapshot', 'terminal_name_snapshot', 'source_invoice_id', 'source_online_order_id', 'number', 'status', 'payment_method', 'subtotal_amount', 'discount_amount', 'tax_amount', 'total_amount', 'sold_at', 'metadata', 'idempotency_key'])]
class Sale extends Model
{
    use SoftDeletes;
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

    public function virtualDevice(): BelongsTo
    {
        return $this->belongsTo(VirtualDevice::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function sourceInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'source_invoice_id');
    }

    public function sourceOnlineOrder(): BelongsTo
    {
        return $this->belongsTo(OnlineOrder::class, 'source_online_order_id');
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
