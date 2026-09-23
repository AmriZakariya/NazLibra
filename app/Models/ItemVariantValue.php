<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Which value a variant holds on one axis. */
#[Fillable(['tenant_id', 'item_variant_id', 'option_type_id', 'option_value_id'])]
class ItemVariantValue extends Model
{
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ItemVariant::class, 'item_variant_id');
    }

    public function optionType(): BelongsTo
    {
        return $this->belongsTo(OptionType::class);
    }

    public function optionValue(): BelongsTo
    {
        return $this->belongsTo(OptionValue::class);
    }
}
