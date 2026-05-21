<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tenant_id', 'kind', 'name', 'client_type', 'phone', 'email', 'cin', 'ice', 'address', 'tags', 'advance_balance', 'outstanding_balance', 'fine_balance', 'membership_expires_at'])]
class Contact extends Model
{
    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'membership_expires_at' => 'date',
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
}
