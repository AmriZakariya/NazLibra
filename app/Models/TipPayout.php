<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tips handed over to a barber: what turns "owed" into "paid". */
#[Fillable(['tenant_id', 'user_id', 'amount', 'method', 'paid_at', 'paid_by_user_id', 'cash_register_movement_id', 'note'])]
class TipPayout extends Model
{
    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by_user_id');
    }
}
