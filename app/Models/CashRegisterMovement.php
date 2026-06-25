<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'cash_register_session_id', 'user_id', 'virtual_device_id', 'actor_name_snapshot', 'terminal_name_snapshot', 'sale_id', 'account_transaction_id', 'number', 'type', 'direction', 'amount', 'balance_after', 'payment_method', 'reference', 'note', 'moved_at', 'metadata'])]
class CashRegisterMovement extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'balance_after' => 'float',
            'moved_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CashRegisterSession::class, 'cash_register_session_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function virtualDevice(): BelongsTo
    {
        return $this->belongsTo(VirtualDevice::class);
    }

    public function accountTransaction(): BelongsTo
    {
        return $this->belongsTo(AccountTransaction::class);
    }
}
