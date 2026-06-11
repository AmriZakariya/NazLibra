<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'name', 'code', 'type', 'value', 'scope', 'minimum_amount', 'included_item_ids', 'excluded_item_ids', 'payment_methods', 'starts_at', 'ends_at', 'is_active', 'notes', 'metadata'])]
class DiscountRule extends Model
{
    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'minimum_amount' => 'decimal:2',
            'included_item_ids' => 'array',
            'excluded_item_ids' => 'array',
            'payment_methods' => 'array',
            'starts_at' => 'date',
            'ends_at' => 'date',
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(function (Builder $builder): void {
                $builder->whereNull('starts_at')->orWhereDate('starts_at', '<=', now()->toDateString());
            })
            ->where(function (Builder $builder): void {
                $builder->whereNull('ends_at')->orWhereDate('ends_at', '>=', now()->toDateString());
            });
    }
}
