<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tenant_id', 'store_key', 'name', 'type', 'bank_name', 'account_number', 'holder_name', 'opening_balance', 'current_balance', 'description', 'is_active'])]
class FinancialAccount extends Model
{
    protected function casts(): array
    {
        return [
            'opening_balance' => 'float',
            'current_balance' => 'float',
            'is_active' => 'boolean',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(AccountTransaction::class);
    }
}
