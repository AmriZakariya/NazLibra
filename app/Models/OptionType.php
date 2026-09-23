<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An axis an article can vary on: Taille, Couleur, Format. */
#[Fillable(['tenant_id', 'name', 'presentation', 'sort_order', 'is_active'])]
class OptionType extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function values(): HasMany
    {
        // Shop order, not alphabetical: S, M, L is not a sort.
        return $this->hasMany(OptionValue::class)->orderBy('sort_order')->orderBy('id');
    }

    public function activeValues(): HasMany
    {
        return $this->values()->where('is_active', true);
    }
}
