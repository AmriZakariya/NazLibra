<?php

namespace App\Services\GiftCards;

use App\Exceptions\GiftCardException;
use App\Models\GiftCard;
use App\Models\GiftCardTransaction;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Tenant;
use App\Support\Money;
use App\Support\TenantClock;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Everything that touches a gift card's balance.
 *
 * The balance is never read-then-written: every debit is ONE conditional
 * UPDATE (`balance >= amount`), so two tills spending the same card at the
 * same moment cannot both win, on MySQL (row lock) or SQLite alike.
 */
class GiftCardService
{
    /** No I, L or O, no 0 or 1: a code read over the phone or off a receipt must not be ambiguous. */
    public const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public const CODE_LENGTH = 8;

    public const DEFAULT_VALIDITY_MONTHS = 12;

    public const MAX_AMOUNT = 1000000;

    /** Upper case, no spaces, dashes or dots. */
    public static function normalize(?string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $code) ?? '');
    }

    /** « ABCD-EFGH » */
    public static function display(?string $code): string
    {
        $code = self::normalize($code);

        return strlen($code) === self::CODE_LENGTH ? substr($code, 0, 4).'-'.substr($code, 4) : $code;
    }

    public static function isValidFormat(string $code): bool
    {
        return (bool) preg_match('/^['.self::ALPHABET.']{'.self::CODE_LENGTH.'}$/', $code);
    }

    public static function randomCode(): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $code = '';
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        return $code;
    }

    public function codeIsTaken(int $tenantId, string $code): bool
    {
        return GiftCard::where('tenant_id', $tenantId)->where('code', $code)->exists();
    }

    /** A fresh code nobody has in this shop. */
    public function generateCode(int $tenantId): string
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $code = self::randomCode();
            if (! $this->codeIsTaken($tenantId, $code)) {
                return $code;
            }
        }

        throw new GiftCardException('Impossible de générer un code de carte cadeau. Réessayez.', 'code_generation');
    }

    /**
     * The code a card sold on a till will carry: the one the till already
     * printed on the ticket when it is well formed and free, else a new one.
     * See issue(): the till may be offline, so it generates its own code.
     */
    public function codeFor(int $tenantId, ?string $preferred): string
    {
        $preferred = self::normalize($preferred);
        if ($preferred !== '' && self::isValidFormat($preferred) && ! $this->codeIsTaken($tenantId, $preferred)) {
            return $preferred;
        }

        return $this->generateCode($tenantId);
    }

    /** Months a new card lives, from the shop's settings; 0 means it never expires. */
    public static function validityMonths(Tenant $tenant): int
    {
        $value = data_get($tenant->settings, 'gift_cards.validity_months');

        return $value === null || $value === '' ? self::DEFAULT_VALIDITY_MONTHS : max(0, min(120, (int) $value));
    }

    public static function today(Tenant $tenant): CarbonInterface
    {
        return now(TenantClock::timezone($tenant))->startOfDay();
    }

    public static function defaultExpiry(Tenant $tenant, CarbonInterface $from): ?CarbonInterface
    {
        $months = self::validityMonths($tenant);

        return $months === 0
            ? null
            : $from->copy()->setTimezone(TenantClock::timezone($tenant))->startOfDay()->addMonthsNoOverflow($months);
    }

    /** A card created by a sale line. Idempotent per sale line. */
    public function issue(Tenant $tenant, Sale $sale, SaleItem $line, float $amount, ?string $preferredCode = null, array $details = [], ?int $userId = null): GiftCard
    {
        $existing = GiftCard::where('tenant_id', $tenant->id)->where('sale_item_id', $line->id)->first();
        if ($existing) {
            return $existing;
        }

        $amount = round($amount, 2);
        $soldAt = $sale->sold_at ?? now();
        $card = GiftCard::create([
            'tenant_id' => $tenant->id,
            'code' => $this->codeFor($tenant->id, $preferredCode),
            'initial_amount' => $amount,
            'balance' => $amount,
            'status' => GiftCard::ACTIVE,
            'expires_at' => self::defaultExpiry($tenant, $soldAt)?->toDateString(),
            'buyer_contact_id' => $sale->contact_id,
            'recipient_name' => $this->clean($details['recipient_name'] ?? null, 160),
            'recipient_phone' => $this->clean($details['recipient_phone'] ?? null, 40),
            'sale_id' => $sale->id,
            'sale_item_id' => $line->id,
            'issued_by_user_id' => $userId,
            'sold_at' => $soldAt,
        ]);
        $this->record($card, GiftCardTransaction::SALE, $amount, $amount, $sale->id, null, $userId, 'Vente '.$sale->number);

        return $card;
    }

    /** The card with this code, optionally locked for the rest of the transaction. */
    public function find(Tenant $tenant, ?string $code, bool $lock = false): ?GiftCard
    {
        $code = self::normalize($code);
        if ($code === '') {
            return null;
        }
        $query = GiftCard::where('tenant_id', $tenant->id)->where('code', $code);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    /** Marks a card whose date has passed as expired. Cheap and idempotent. */
    public function refreshStatus(Tenant $tenant, GiftCard $card): GiftCard
    {
        if ($card->status === GiftCard::ACTIVE && $card->expires_at !== null
            && $card->expires_at->toDateString() < self::today($tenant)->toDateString()) {
            GiftCard::whereKey($card->id)->where('status', GiftCard::ACTIVE)->update(['status' => GiftCard::EXPIRED]);
            $card->refresh();
        }

        return $card;
    }

    /**
     * Why this card cannot pay `$amount` right now, or null when it can.
     * `$balance` is passed in so the same check serves the lookup and the
     * locked re-check at redemption.
     */
    public function refusal(Tenant $tenant, ?GiftCard $card, float $amount = 0.0): ?GiftCardException
    {
        $money = Money::for($tenant);
        if ($card === null) {
            return new GiftCardException('Carte cadeau introuvable. Vérifiez le code.', 'not_found');
        }
        $card = $this->refreshStatus($tenant, $card);

        return match (true) {
            $card->status === GiftCard::CANCELLED => new GiftCardException('Cette carte cadeau a été annulée.', 'cancelled'),
            $card->status === GiftCard::EXPIRED => new GiftCardException('Cette carte cadeau a expiré le '.$card->expires_at?->format('d/m/Y').'.', 'expired'),
            $card->status === GiftCard::USED || (float) $card->balance <= 0.004 => new GiftCardException('Cette carte cadeau est épuisée.', 'used'),
            $amount > 0 && $amount - 0.004 > (float) $card->balance => new GiftCardException(
                'Solde insuffisant : '.$money->format((float) $card->balance).' disponible sur la carte.',
                'insufficient_balance',
            ),
            default => null,
        };
    }

    /**
     * Spends `$amount` of the card on a sale. Call inside the sale's
     * transaction: if anything later fails, the debit rolls back with it.
     */
    public function redeem(Tenant $tenant, string $code, float $amount, Sale $sale, ?int $userId = null): GiftCardTransaction
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new GiftCardException('Le montant à prélever sur la carte doit être positif.', 'invalid_amount');
        }

        return DB::transaction(function () use ($tenant, $code, $amount, $sale, $userId): GiftCardTransaction {
            $card = $this->find($tenant, $code, lock: true);
            if ($refusal = $this->refusal($tenant, $card, $amount)) {
                throw $refusal;
            }

            // One conditional UPDATE: the guard is in the statement itself.
            $debited = GiftCard::whereKey($card->id)
                ->where('status', GiftCard::ACTIVE)
                ->where('balance', '>=', $amount - 0.004)
                ->update([
                    'balance' => DB::raw('balance - '.number_format($amount, 2, '.', '')),
                    'updated_at' => now(),
                ]);
            if ($debited !== 1) {
                throw new GiftCardException('Solde insuffisant : la carte vient d’être utilisée.', 'insufficient_balance');
            }

            $card->refresh();
            if ((float) $card->balance <= 0.004) {
                $card->update(['status' => GiftCard::USED, 'balance' => 0]);
            }

            return $this->record($card, GiftCardTransaction::REDEEM, -$amount, (float) $card->balance, $sale->id, null, $userId, 'Paiement vente '.$sale->number);
        });
    }

    /** Puts money back on the card the sale was paid with. */
    public function refund(Tenant $tenant, GiftCard $card, float $amount, Sale $sale, ?int $saleReturnId, ?int $userId = null, ?string $reason = null): GiftCardTransaction
    {
        $amount = round($amount, 2);

        return DB::transaction(function () use ($tenant, $card, $amount, $sale, $saleReturnId, $userId, $reason): GiftCardTransaction {
            GiftCard::whereKey($card->id)->lockForUpdate()->first();
            GiftCard::whereKey($card->id)->update([
                'balance' => DB::raw('balance + '.number_format($amount, 2, '.', '')),
                'updated_at' => now(),
            ]);
            $card->refresh();

            // Money coming back re-opens a spent card; one past its date gets
            // a month, or the refund would sit on a card nobody can use.
            $updates = [];
            if ($card->status === GiftCard::USED) {
                $updates['status'] = GiftCard::ACTIVE;
            }
            $today = self::today($tenant);
            if ($card->expires_at !== null && $card->expires_at->toDateString() < $today->toDateString()) {
                $updates['expires_at'] = $today->copy()->addMonth()->toDateString();
                $updates['status'] = GiftCard::ACTIVE;
            }
            if ($updates && $card->status !== GiftCard::CANCELLED) {
                $card->update($updates);
            }

            return $this->record($card, GiftCardTransaction::REFUND, $amount, (float) $card->balance, $sale->id, $saleReturnId, $userId, $reason ?: 'Remboursement vente '.$sale->number);
        });
    }

    /** A manual correction, always with a reason. `$delta` is signed. */
    public function adjust(Tenant $tenant, GiftCard $card, float $delta, string $reason, ?int $userId = null, ?string $expiresAt = null): GiftCardTransaction
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new GiftCardException('Indiquez le motif de l’ajustement.', 'reason_required');
        }
        $delta = round($delta, 2);

        return DB::transaction(function () use ($tenant, $card, $delta, $reason, $userId, $expiresAt): GiftCardTransaction {
            $locked = GiftCard::where('tenant_id', $tenant->id)->whereKey($card->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === GiftCard::CANCELLED) {
                throw new GiftCardException('Une carte annulée ne peut plus être ajustée.', 'cancelled');
            }
            if ($delta < 0 && -$delta - 0.004 > (float) $locked->balance) {
                throw new GiftCardException('L’ajustement dépasse le solde de la carte.', 'insufficient_balance');
            }

            $locked->balance = round((float) $locked->balance + $delta, 2);
            if ($expiresAt !== null) {
                $locked->expires_at = $expiresAt === '' ? null : $expiresAt;
            }
            if ($locked->balance <= 0.004) {
                $locked->status = GiftCard::USED;
            } elseif (in_array($locked->status, [GiftCard::USED, GiftCard::EXPIRED], true)) {
                $notPast = $locked->expires_at === null || $locked->expires_at->toDateString() >= self::today($tenant)->toDateString();
                $locked->status = $notPast ? GiftCard::ACTIVE : GiftCard::EXPIRED;
            }
            $locked->save();
            $card->refresh();

            return $this->record($locked, GiftCardTransaction::ADJUST, $delta, (float) $locked->balance, null, null, $userId, $reason);
        });
    }

    /** Voids the card; whatever was left on it is written off with a movement. */
    public function cancel(Tenant $tenant, GiftCard $card, string $reason, ?int $userId = null): GiftCardTransaction
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new GiftCardException('Indiquez le motif de l’annulation.', 'reason_required');
        }

        return DB::transaction(function () use ($tenant, $card, $reason, $userId): GiftCardTransaction {
            $locked = GiftCard::where('tenant_id', $tenant->id)->whereKey($card->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === GiftCard::CANCELLED) {
                throw new GiftCardException('Cette carte est déjà annulée.', 'cancelled');
            }
            $left = (float) $locked->balance;
            $locked->update(['status' => GiftCard::CANCELLED, 'balance' => 0]);
            $card->refresh();

            return $this->record($locked, GiftCardTransaction::CANCEL, -$left, 0.0, null, null, $userId, $reason);
        });
    }

    /** The card as the till and the lookup show it. */
    public function payload(Tenant $tenant, GiftCard $card): array
    {
        $card = $this->refreshStatus($tenant, $card);

        return [
            'id' => $card->id,
            'code' => $card->code,
            'display_code' => $card->displayCode(),
            'initial_amount' => (float) $card->initial_amount,
            'balance' => (float) $card->balance,
            'status' => $card->status,
            'expires_at' => $card->expires_at?->toDateString(),
            'recipient_name' => $card->recipient_name,
            'usable' => $this->refusal($tenant, $card) === null,
        ];
    }

    private function record(GiftCard $card, string $type, float $amount, float $balanceAfter, ?int $saleId, ?int $saleReturnId, ?int $userId, ?string $reason): GiftCardTransaction
    {
        return GiftCardTransaction::create([
            'tenant_id' => $card->tenant_id,
            'gift_card_id' => $card->id,
            'type' => $type,
            'amount' => round($amount, 2),
            'balance_after' => round($balanceAfter, 2),
            'sale_id' => $saleId,
            'sale_return_id' => $saleReturnId,
            'user_id' => $userId,
            'reason' => $reason ? mb_substr($reason, 0, 255) : null,
        ]);
    }

    private function clean(?string $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
