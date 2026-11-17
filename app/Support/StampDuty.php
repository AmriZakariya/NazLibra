<?php

namespace App\Support;

use App\Models\Sale;
use App\Models\Tenant;

/**
 * The « droit de timbre » of the countries that levy one on invoices.
 *
 * Two rules, picked by the shop's country:
 *  - RULE_FIXED (Tunisia): a fixed fee added to every invoice;
 *  - RULE_SCALE (Algeria): a percentage scale on the invoice total, owed only
 *    when the invoice is paid in cash.
 *
 * Stored under settings.invoicing.stamp_duty as {enabled, amount}, keeping
 * only what differs from the country's default: a Tunisian shop has it ON at
 * 1.000, every other shop has it OFF, and an absent key means "the default".
 * The amount is copied onto each invoice when it is created, so changing the
 * setting later never rewrites an invoice already issued.
 */
class StampDuty
{
    public const SETTINGS_KEY = 'invoicing.stamp_duty';

    public const RULE_FIXED = 'fixed';

    public const RULE_SCALE = 'scale';

    /** Countries that levy it, with their rule (and the usual amount for a fixed one). */
    private const COUNTRY_RULES = [
        'TN' => ['rule' => self::RULE_FIXED, 'amount' => '1.00'],
        'DZ' => ['rule' => self::RULE_SCALE],
    ];

    private const FALLBACK_AMOUNT = '1.00';

    /**
     * Algeria — Loi de finances 2025, art. 100 du Code du timbre. The ONE place
     * to edit when a finance law changes the scale.
     *  - threshold: a total up to this amount owes nothing;
     *  - brackets: [upper bound of the total (null = no limit), rate in
     *    thousandths]; the rate applies to the WHOLE amount, not marginally;
     *  - minimum: the duty is rounded UP to the whole dinar, never below this.
     */
    public const DZ_SCALE = [
        'threshold' => 300,
        'minimum' => 5,
        'brackets' => [
            [30000, 10],   // 1 %
            [100000, 15],  // 1.5 %
            [null, 20],    // 2 %
        ],
    ];

    /** Whether the shop's country levies it (and so shows the setting). */
    public static function levied(?Tenant $tenant): bool
    {
        return isset(self::COUNTRY_RULES[BusinessIds::country($tenant)]);
    }

    /** « fixed » or « scale »: how the shop's country computes it. */
    public static function rule(?Tenant $tenant): string
    {
        return self::COUNTRY_RULES[BusinessIds::country($tenant)]['rule'] ?? self::RULE_FIXED;
    }

    /** Whether the shop's duty depends on the invoice total and on cash payment. */
    public static function usesScale(?Tenant $tenant): bool
    {
        return self::levied($tenant) && self::rule($tenant) === self::RULE_SCALE;
    }

    /** Whether a module invoice form must ask « Réglé en espèces ». */
    public static function asksCash(?Tenant $tenant): bool
    {
        return self::usesScale($tenant) && self::settings($tenant)['enabled'];
    }

    /** @return array{enabled: bool, amount: string} what the country gives a shop that changed nothing */
    public static function defaults(?Tenant $tenant): array
    {
        $country = self::COUNTRY_RULES[BusinessIds::country($tenant)] ?? null;

        return ['enabled' => $country !== null, 'amount' => $country['amount'] ?? self::FALLBACK_AMOUNT];
    }

    /** @return array{enabled: bool, amount: string} the shop's own setting over the default */
    public static function settings(?Tenant $tenant): array
    {
        $out = self::defaults($tenant);
        $own = data_get($tenant?->settings, self::SETTINGS_KEY, []);
        if (is_array($own)) {
            if (array_key_exists('enabled', $own)) {
                $out['enabled'] = (bool) $own['enabled'];
            }
            if (! self::usesScale($tenant) && isset($own['amount']) && is_numeric($own['amount']) && (float) $own['amount'] > 0) {
                $out['amount'] = self::format($own['amount']);
            }
        }

        return $out;
    }

    /** Whether Paramètres shows the setting: levied here, or already switched on by the shop. */
    public static function shown(?Tenant $tenant): bool
    {
        return self::levied($tenant) || (bool) data_get($tenant?->settings, self::SETTINGS_KEY.'.enabled', false);
    }

    /**
     * The FIXED amount a new invoice carries: « 0.00 » when there is none.
     * A scale shop (Algeria) has no fixed amount — use forInvoice().
     */
    public static function amount(?Tenant $tenant): string
    {
        $settings = self::settings($tenant);

        return $settings['enabled'] && ! self::usesScale($tenant) ? $settings['amount'] : '0.00';
    }

    /**
     * The duty of an invoice whose total TTC (stamp excluded) is [$totalTtc]:
     * the fixed amount, or the Algerian scale when it is paid in cash.
     */
    public static function forInvoice(?Tenant $tenant, string $totalTtc, bool $paidInCash): string
    {
        if (! self::settings($tenant)['enabled']) {
            return '0.00';
        }
        if (! self::usesScale($tenant)) {
            return self::amount($tenant);
        }

        return $paidInCash ? self::scale($totalTtc) : '0.00';
    }

    /**
     * The Algerian scale applied to an amount, whatever the shop's setting.
     * Integer arithmetic on centimes: no float drift at a bracket boundary.
     */
    public static function scale(string $totalTtc): string
    {
        $cents = (int) round((float) $totalTtc * 100);
        if ($cents <= self::DZ_SCALE['threshold'] * 100) {
            return '0.00';
        }
        $permille = 0;
        foreach (self::DZ_SCALE['brackets'] as [$upTo, $rate]) {
            if ($upTo === null || $cents <= $upTo * 100) {
                $permille = $rate;
                break;
            }
        }
        // dinars = cents/100 * permille/1000, rounded UP to the whole dinar.
        $dinars = intdiv($cents * $permille + 99999, 100000);

        return number_format(max($dinars, self::DZ_SCALE['minimum']), 2, '.', '');
    }

    /**
     * The duty of an invoice raised from a sale.
     *
     * Split payments: the scale is applied to the CASH PORTION only (the sum of
     * the sale's cash payments, change already excluded), because that is the
     * part the law taxes; card/transfer/cheque portions are exempt. A sale
     * without payment rows (legacy data) counts as wholly cash only when its
     * payment_method is exactly « cash ». The result is fixed when the invoice
     * is first raised, so a cash payment recorded afterwards adds no stamp.
     */
    public static function forSale(?Tenant $tenant, Sale $sale): string
    {
        if (! self::usesScale($tenant)) {
            return self::amount($tenant);
        }
        $sale->loadMissing('payments');
        if ($sale->payments->isEmpty()) {
            $cash = $sale->payment_method === 'cash' ? (float) $sale->total_amount : 0.0;
        } else {
            $cash = (float) $sale->payments->where('method', 'cash')->sum('amount');
        }

        return self::forInvoice($tenant, number_format($cash, 2, '.', ''), $cash > 0);
    }

    /**
     * What to store for a submitted choice: only the differences from the
     * default, or null when it is the default (the key is then removed).
     *
     * @return array<string, mixed>|null
     */
    public static function overridesFrom(?Tenant $tenant, bool $enabled, mixed $amount): ?array
    {
        $defaults = self::defaults($tenant);
        $amount = self::format($amount);
        $own = [];
        if ($enabled !== $defaults['enabled']) {
            $own['enabled'] = $enabled;
        }
        // A scale shop has no amount to override.
        if (! self::usesScale($tenant) && $amount !== $defaults['amount']) {
            $own['amount'] = $amount;
        }

        return $own === [] ? null : $own;
    }

    private static function format(mixed $amount): string
    {
        return number_format(max(0, round((float) str_replace(',', '.', (string) $amount), 2)), 2, '.', '');
    }
}
