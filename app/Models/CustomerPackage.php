<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A package a customer bought: sessions left on a service. */
#[Fillable(['tenant_id', 'contact_id', 'package_id', 'name', 'item_id', 'variant_id', 'sessions_total', 'sessions_remaining', 'price_paid', 'expires_at', 'status', 'sale_id', 'sale_item_id', 'sold_at'])]
class CustomerPackage extends Model
{
    public const ACTIVE = 'active';

    public const USED = 'used';

    public const EXPIRED = 'expired';

    public const CANCELLED = 'cancelled';

    protected function casts(): array
    {
        return [
            'price_paid' => 'decimal:2',
            'expires_at' => 'date',
            'sold_at' => 'datetime',
            'sessions_total' => 'integer',
            'sessions_remaining' => 'integer',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CustomerPackageMovement::class)->orderBy('id');
    }

    /** Usable today: active, sessions left, not past its date. */
    public function isUsable(): bool
    {
        return $this->status === self::ACTIVE
            && $this->sessions_remaining > 0
            && ($this->expires_at === null || ! $this->expires_at->isPast() || $this->expires_at->isToday());
    }
}
