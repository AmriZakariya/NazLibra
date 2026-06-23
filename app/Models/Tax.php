<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['tenant_id', 'name', 'rate', 'description', 'is_active'])]
class Tax extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'rate' => 'float',
            'is_active' => 'boolean',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }
}
