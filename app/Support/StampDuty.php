<?php

namespace App\Support;

use App\Models\Tenant;

/**
 * The Tunisian « droit de timbre »: a fixed fee added to every invoice.
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

    /** Countries that levy it, with the usual amount. */
    private const COUNTRY_AMOUNTS = ['TN' => '1.00'];

    private const FALLBACK_AMOUNT = '1.00';

    /** Whether the shop's country levies it (and so shows the setting). */
    public static function levied(?Tenant $tenant): bool
    {
        return isset(self::COUNTRY_AMOUNTS[BusinessIds::country($tenant)]);
    }

    /** @return array{enabled: bool, amount: string} what the country gives a shop that changed nothing */
    public static function defaults(?Tenant $tenant): array
    {
        $amount = self::COUNTRY_AMOUNTS[BusinessIds::country($tenant)] ?? null;

        return ['enabled' => $amount !== null, 'amount' => $amount ?? self::FALLBACK_AMOUNT];
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
            if (isset($own['amount']) && is_numeric($own['amount']) && (float) $own['amount'] > 0) {
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

    /** The amount a new invoice of this shop carries: « 0.00 » when there is none. */
    public static function amount(?Tenant $tenant): string
    {
        $settings = self::settings($tenant);

        return $settings['enabled'] ? $settings['amount'] : '0.00';
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
        if ($amount !== $defaults['amount']) {
            $own['amount'] = $amount;
        }

        return $own === [] ? null : $own;
    }

    private static function format(mixed $amount): string
    {
        return number_format(max(0, round((float) str_replace(',', '.', (string) $amount), 2)), 2, '.', '');
    }
}
