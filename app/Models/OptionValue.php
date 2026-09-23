<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One position on an axis: S, or Rouge. */
#[Fillable([
    'tenant_id', 'option_type_id', 'value', 'short_label', 'swatch',
    'sort_order', 'is_active',
])]
class OptionValue extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function optionType(): BelongsTo
    {
        return $this->belongsTo(OptionType::class);
    }

    /** What fits on a till button. */
    public function label(): string
    {
        return $this->short_label ?: $this->value;
    }
}
