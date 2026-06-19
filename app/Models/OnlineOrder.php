<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'tenant_id',
    'contact_id',
    'user_id',
    'converted_sale_id',
    'converted_by',
    'converted_at',
    'number',
    'channel',
    'status',
    'payment_status',
    'customer_name',
    'customer_phone',
    'customer_email',
    'delivery_address',
    'ordered_at',
    'expected_at',
    'subtotal_amount',
    'discount_amount',
    'deposit_amount',
    'total_amount',
    'customer_note',
    'internal_note',
    'metadata',
])]
class OnlineOrder extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'ordered_at' => 'datetime',
            'expected_at' => 'date',
            'converted_at' => 'datetime',
            'subtotal_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'deposit_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function convertedSale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'converted_sale_id');
    }

    public function converter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'converted_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OnlineOrderItem::class);
    }
}
