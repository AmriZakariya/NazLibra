<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'tenant_id',
    'kind',
    'code',
    'store_id',
    'name',
    'client_type',
    'status',
    'phone',
    'email',
    'cin',
    'ice',
    'credit_limit',
    'opening_balance',
    'tax_number',
    'address',
    'country',
    'state',
    'city',
    'postcode',
    'location_link',
    'shipping_country',
    'shipping_state',
    'shipping_city',
    'shipping_postcode',
    'shipping_address',
    'shipping_location_link',
    'price_level_type',
    'price_level',
    'attachment_path',
    'tags',
    'advance_balance',
    'outstanding_balance',
    'fine_balance',
    'membership_expires_at',
])]
class Contact extends Model
{
    use SoftDeletes;
    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'membership_expires_at' => 'date',
            'credit_limit' => 'decimal:2',
            'opening_balance' => 'decimal:2',
            'advance_balance' => 'decimal:2',
            'outstanding_balance' => 'decimal:2',
            'fine_balance' => 'decimal:2',
            'price_level' => 'decimal:2',
        ];
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class, 'member_id');
    }

    public function customerAdvances(): HasMany
    {
        return $this->hasMany(CustomerAdvance::class);
    }

    public function supplierPurchases(): HasMany
    {
        return $this->hasMany(Purchase::class, 'supplier_id');
    }

    public function supplierPurchaseReturns(): HasMany
    {
        return $this->hasMany(PurchaseReturn::class, 'supplier_id');
    }
}
