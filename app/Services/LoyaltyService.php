<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\LoyaltyPointTransaction;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;

/**
 * Encapsulates all loyalty point business rules.
 *
 * Config (from Tenant.settings['loyalty']):
 *   enabled          bool    — master on/off switch
 *   earn_rate        float   — points earned per currency unit spent (default: 1)
 *   redeem_rate      float   — currency value of 1 point (default: 0.1)
 *   min_redeem_points int    — minimum points required to redeem (default: 50)
 *   max_redeem_pct   float   — max % of sale total payable via points (default: 30)
 */
class LoyaltyService
{
    // ── Config helpers ─────────────────────────────────────────────────────────

    public function config(object $tenant): array
    {
        $s = (array) ($tenant->settings['loyalty'] ?? []);

        return [
            'enabled'           => (bool)  ($s['enabled']           ?? false),
            'earn_rate'         => (float) ($s['earn_rate']         ?? 1.0),
            'redeem_rate'       => (float) ($s['redeem_rate']       ?? 0.1),
            'min_redeem_points' => (int)   ($s['min_redeem_points'] ?? 50),
            'max_redeem_pct'    => (float) ($s['max_redeem_pct']    ?? 30.0),
        ];
    }

    public function isEnabled(object $tenant): bool
    {
        return $this->config($tenant)['enabled'];
    }

    // ── Points math ────────────────────────────────────────────────────────────

    /** How many points does a given sale total earn? */
    public function pointsForAmount(float $amount, array $cfg): float
    {
        return round($amount * $cfg['earn_rate'], 2);
    }

    /** Currency value of a given points amount. */
    public function pointsToAmount(float $points, array $cfg): float
    {
        return round($points * $cfg['redeem_rate'], 2);
    }

    /**
     * Validate a redemption request and return the effective discount amount.
     * Throws \InvalidArgumentException with a user-facing message on failure.
     */
    public function validateRedemption(
        float $pointsRequested,
        float $contactBalance,
        float $saleTotal,
        array $cfg,
    ): float {
        if ($pointsRequested <= 0) {
            throw new \InvalidArgumentException('Le nombre de points à utiliser doit être positif.');
        }

        if ($pointsRequested < $cfg['min_redeem_points']) {
            throw new \InvalidArgumentException(
                "Minimum {$cfg['min_redeem_points']} points requis pour utiliser vos points de fidélité."
            );
        }

        if ($pointsRequested > $contactBalance) {
            throw new \InvalidArgumentException(
                "Solde insuffisant : vous avez {$contactBalance} points."
            );
        }

        $discountAmount  = $this->pointsToAmount($pointsRequested, $cfg);
        $maxDiscount     = round($saleTotal * $cfg['max_redeem_pct'] / 100, 2);

        if ($discountAmount > $maxDiscount) {
            throw new \InvalidArgumentException(
                "Les points ne peuvent couvrir que {$cfg['max_redeem_pct']}% du total ({$maxDiscount} {$this->currencyLabel($cfg)})."
            );
        }

        return $discountAmount;
    }

    // ── Write operations (all inside caller's DB transaction) ─────────────────

    /**
     * Credit earned points to a contact after a successful sale.
     * Idempotent: skips if the same idempotency_key already exists.
     */
    public function earn(Contact $contact, Sale $sale, float $points, string $idempotencyKey): void
    {
        if (LoyaltyPointTransaction::where('idempotency_key', $idempotencyKey)->exists()) {
            return; // idempotent retry
        }

        $contact->lockForUpdate();
        $contact->refresh();

        $newBalance = round((float) $contact->loyalty_points + $points, 2);

        LoyaltyPointTransaction::create([
            'tenant_id'        => $contact->tenant_id,
            'contact_id'       => $contact->id,
            'sale_id'          => $sale->id,
            'type'             => 'earned',
            'points_amount'    => $points,
            'balance_after'    => $newBalance,
            'note'             => "Vente {$sale->number}",
            'idempotency_key'  => $idempotencyKey,
            'recorded_at'      => $sale->sold_at,
        ]);

        $contact->update(['loyalty_points' => $newBalance]);
    }

    /**
     * Debit redeemed points from a contact during a sale.
     * Must be called inside the sale DB transaction (lockForUpdate already acquired by caller).
     */
    public function redeem(Contact $contact, Sale $sale, float $points, string $idempotencyKey): void
    {
        if ((float) $contact->loyalty_points < $points) {
            throw new \RuntimeException('Solde de points insuffisant.');
        }

        $newBalance = round((float) $contact->loyalty_points - $points, 2);

        LoyaltyPointTransaction::create([
            'tenant_id'        => $contact->tenant_id,
            'contact_id'       => $contact->id,
            'sale_id'          => $sale->id,
            'type'             => 'redeemed',
            'points_amount'    => -$points,
            'balance_after'    => $newBalance,
            'note'             => "Remboursement sur vente {$sale->number}",
            'idempotency_key'  => $idempotencyKey,
            'recorded_at'      => $sale->sold_at,
        ]);

        $contact->update(['loyalty_points' => $newBalance]);
    }

    /**
     * Reverse earned points when a sale is refunded/cancelled.
     * Safe to call multiple times — checks if a reversal already exists.
     */
    public function reverseEarned(Contact $contact, Sale $sale): void
    {
        $reverseKey = 'reverse-earn-sale-'.$sale->id;

        if (LoyaltyPointTransaction::where('idempotency_key', $reverseKey)->exists()) {
            return;
        }

        $earned = (float) $sale->loyalty_points_earned;
        if ($earned <= 0) {
            return;
        }

        DB::transaction(function () use ($contact, $sale, $earned, $reverseKey): void {
            $contact->lockForUpdate();
            $contact->refresh();

            $newBalance = max(0, round((float) $contact->loyalty_points - $earned, 2));

            LoyaltyPointTransaction::create([
                'tenant_id'        => $contact->tenant_id,
                'contact_id'       => $contact->id,
                'sale_id'          => $sale->id,
                'type'             => 'reversed',
                'points_amount'    => -$earned,
                'balance_after'    => $newBalance,
                'note'             => "Annulation vente {$sale->number}",
                'idempotency_key'  => $reverseKey,
                'recorded_at'      => now(),
            ]);

            $contact->update(['loyalty_points' => $newBalance]);
        });
    }

    /**
     * Manual admin adjustment (positive or negative).
     */
    public function adjust(Contact $contact, float $points, string $note, int $tenantId): LoyaltyPointTransaction
    {
        return DB::transaction(function () use ($contact, $points, $note, $tenantId): LoyaltyPointTransaction {
            $contact->lockForUpdate();
            $contact->refresh();

            $newBalance = max(0, round((float) $contact->loyalty_points + $points, 2));

            $tx = LoyaltyPointTransaction::create([
                'tenant_id'        => $tenantId,
                'contact_id'       => $contact->id,
                'sale_id'          => null,
                'type'             => 'adjusted',
                'points_amount'    => $points,
                'balance_after'    => $newBalance,
                'note'             => $note,
                'idempotency_key'  => 'adj-'.uniqid('', true),
                'recorded_at'      => now(),
            ]);

            $contact->update(['loyalty_points' => $newBalance]);

            return $tx;
        });
    }

    // ── Private ────────────────────────────────────────────────────────────────

    private function currencyLabel(array $cfg): string
    {
        return 'MAD'; // TODO: pass currency through if multi-currency needed
    }
}
