<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tenant_id', 'financial_account_id', 'opened_by', 'closed_by', 'store_key', 'number', 'status', 'opening_amount', 'expected_cash_amount', 'counted_cash_amount', 'difference_amount', 'opened_at', 'closed_at', 'note', 'closing_note', 'metadata'])]
class CashRegisterSession extends Model
{
    protected function casts(): array
    {
        return [
            'opening_amount' => 'float',
            'expected_cash_amount' => 'float',
            'counted_cash_amount' => 'float',
            'difference_amount' => 'float',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'financial_account_id');
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashRegisterMovement::class);
    }
}
