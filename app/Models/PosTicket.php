<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'contact_id', 'user_id', 'virtual_device_id', 'actor_name_snapshot', 'terminal_name_snapshot', 'converted_sale_id', 'number', 'status', 'cart', 'subtotal_amount', 'discount_amount', 'discount_type', 'discount_value', 'coupon_code', 'coupon_amount', 'tax_amount', 'total_amount', 'note', 'held_at', 'name', 'opened_at'])]
class PosTicket extends Model
{
    use SoftDeletes;

    /** What a person calls it: its name, or its number when it has none. */
    public function displayName(): string
    {
        return filled($this->name) ? (string) $this->name : (string) $this->number;
    }

    /** When it was first opened — held_at moves on every re-park. */
    public function openedAt(): ?\Illuminate\Support\Carbon
    {
        return $this->opened_at ?? $this->created_at ?? $this->held_at;
    }

    protected function casts(): array
    {
        return [
            'cart' => 'array',
            'held_at' => 'datetime',
            'opened_at' => 'datetime',
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

    public function convertedSale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'converted_sale_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function virtualDevice(): BelongsTo
    {
        return $this->belongsTo(VirtualDevice::class);
    }
}
