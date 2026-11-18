<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A prepaid card: money the shop owes until it is spent. The balance only
 * moves through App\Services\GiftCards\GiftCardService, which writes a
 * gift_card_transactions row for each change.
 */
#[Fillable(['tenant_id', 'code', 'initial_amount', 'balance', 'status', 'expires_at', 'buyer_contact_id', 'recipient_name', 'recipient_phone', 'sale_id', 'sale_item_id', 'issued_by_user_id', 'note', 'sold_at'])]
class GiftCard extends Model
{
    public const ACTIVE = 'active';

    public const USED = 'used';

    public const EXPIRED = 'expired';

    public const CANCELLED = 'cancelled';

    protected function casts(): array
    {
        return [
            'initial_amount' => 'decimal:2',
            'balance' => 'decimal:2',
            'expires_at' => 'date',
            'sold_at' => 'datetime',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(GiftCardTransaction::class)->orderBy('id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'buyer_contact_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /** « ABCD-EFGH », the way the code is printed and read out. */
    public function displayCode(): string
    {
        return \App\Services\GiftCards\GiftCardService::display($this->code);
    }
}
