<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** What the shop sells as a package: 5 sessions of a service, at one price. */
#[Fillable(['tenant_id', 'name', 'item_id', 'variant_id', 'sessions', 'price', 'validity_days', 'is_active'])]
class ServicePackage extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'is_active' => 'boolean', 'sessions' => 'integer', 'validity_days' => 'integer'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ItemVariant::class, 'variant_id');
    }
}
