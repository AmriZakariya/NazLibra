<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'financial_account_id', 'related_account_id', 'number', 'type', 'direction', 'amount', 'balance_after', 'payment_method', 'reference', 'note', 'transacted_at', 'metadata'])]
class AccountTransaction extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'balance_after' => 'float',
            'transacted_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'financial_account_id');
    }

    public function relatedAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'related_account_id');
    }
}
