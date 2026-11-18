<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One movement of a gift card's balance: sale, redeem, refund, adjust, cancel. */
#[Fillable(['tenant_id', 'gift_card_id', 'type', 'amount', 'balance_after', 'sale_id', 'sale_return_id', 'user_id', 'reason'])]
class GiftCardTransaction extends Model
{
    public const SALE = 'sale';

    public const REDEEM = 'redeem';

    public const REFUND = 'refund';

    public const ADJUST = 'adjust';

    public const CANCEL = 'cancel';

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'balance_after' => 'decimal:2'];
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(GiftCard::class, 'gift_card_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
