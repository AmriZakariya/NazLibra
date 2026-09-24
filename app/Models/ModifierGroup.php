<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** The rules around a set of line options: "choose 0 to 3 suppléments". */
#[Fillable(['tenant_id', 'name', 'min_select', 'max_select', 'sort_order', 'is_active'])]
class ModifierGroup extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function modifiers(): HasMany
    {
        return $this->hasMany(Modifier::class)->orderBy('sort_order')->orderBy('id');
    }

    /** The articles that offer this group. */
    public function items(): BelongsToMany
    {
        return $this->belongsToMany(Item::class, 'item_modifier_groups')
            ->withPivot(['tenant_id', 'sort_order'])
            ->withTimestamps();
    }

    /** A group nobody can skip: the cashier must pick before the line is valid. */
    public function isRequired(): bool
    {
        return $this->min_select > 0;
    }
}
