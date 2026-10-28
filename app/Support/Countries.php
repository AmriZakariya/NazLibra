<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * The countries a shop can sign up from.
 *
 * Every officially assigned ISO 3166-1 alpha-2 code, minus EXCLUDED, with
 * names in French, English and Arabic. The list itself is generated — see
 * `php artisan castlit:countries-generate` — so this class reads it rather
 * than spelling 249 names by hand, and works on a host without intl.
 */
final class Countries
{
    public const DEFAULT = 'MA';

    /**
     * Left out of an otherwise complete ISO list, deliberately.
     *
     * EH (Western Sahara): Castl-it-POS is a Moroccan company, and shops in
     * Laâyoune or Dakhla sign up under "Maroc". Listing it as a separate
     * country in the signup form would be a political statement the business
     * has not chosen to make. Kosovo (XK) is not here because it has no
     * official ISO code, not because of this list.
     */
    public const EXCLUDED = ['EH'];

    /**
     * Shown first, in this order — where requests actually come from — before
     * every other country alphabetically.
     */
    public const FREQUENT = ['MA', 'FR', 'BE', 'ES', 'DZ', 'TN', 'SN', 'CI', 'CA'];

    /** Shown first in a currency select, in this order; every other one follows by code. */
    public const FREQUENT_CURRENCIES = ['MAD', 'EUR', 'USD', 'XOF', 'XAF', 'DZD', 'TND', 'GBP', 'CHF', 'CAD', 'SAR', 'AED', 'EGP', 'MRU'];

    /**
     * Where a country has several timezones, the one most of its shops are
     * in. A shop whose browser said otherwise (inside that country) keeps its
     * own; a single-zone country needs nothing here.
     */
    public const MAIN_TIMEZONE = [
        'US' => 'America/New_York', 'CA' => 'America/Toronto', 'BR' => 'America/Sao_Paulo',
        'MX' => 'America/Mexico_City', 'AR' => 'America/Argentina/Buenos_Aires', 'CL' => 'America/Santiago',
        'EC' => 'America/Guayaquil', 'RU' => 'Europe/Moscow', 'AU' => 'Australia/Sydney',
        'NZ' => 'Pacific/Auckland', 'ES' => 'Europe/Madrid', 'PT' => 'Europe/Lisbon',
        'DE' => 'Europe/Berlin', 'CD' => 'Africa/Kinshasa', 'ID' => 'Asia/Jakarta', 'KZ' => 'Asia/Almaty',
        'CN' => 'Asia/Shanghai', 'MY' => 'Asia/Kuala_Lumpur', 'UA' => 'Europe/Kyiv', 'MN' => 'Asia/Ulaanbaatar',
        'GL' => 'America/Nuuk', 'PF' => 'Pacific/Tahiti', 'KI' => 'Pacific/Tarawa', 'FM' => 'Pacific/Pohnpei',
        'UZ' => 'Asia/Tashkent', 'CY' => 'Asia/Nicosia', 'PS' => 'Asia/Gaza', 'MH' => 'Pacific/Majuro',
        'UM' => 'Pacific/Midway', 'EH' => 'Africa/Casablanca',
    ];

    /** @var array{codes: list<string>, names: array<string, array<string, string>>, currency: array<string, string>}|null */
    private static ?array $data = null;

    public static function dataPath(): string
    {
        return resource_path('data/countries.php');
    }

    /** @return array{codes: list<string>, names: array<string, array<string, string>>, currency: array<string, string>} */
    private static function data(): array
    {
        return self::$data ??= require self::dataPath();
    }

    /** @return list<string> every accepted code, in no particular display order */
    public static function codes(): array
    {
        return self::data()['codes'];
    }

    /** @return array<string, string> code => French name, in display order */
    public static function all(): array
    {
        return self::localized('fr');
    }

    /**
     * Every country named in [$locale], frequent ones first, then the rest in
     * that language's alphabetical order. French for a language the site does
     * not ship.
     *
     * @return array<string, string>
     */
    public static function localized(string $locale): array
    {
        ['frequent' => $frequent, 'others' => $others] = self::grouped($locale);

        return $frequent + $others;
    }

    /**
     * The same, split for a select with two groups.
     *
     * @return array{frequent: array<string, string>, others: array<string, string>}
     */
    public static function grouped(string $locale): array
    {
        $names = self::data()['names'][$locale] ?? self::data()['names']['fr'];

        $frequent = [];
        foreach (self::FREQUENT as $code) {
            if (isset($names[$code])) {
                $frequent[$code] = $names[$code];
            }
        }

        $others = array_diff_key($names, $frequent);
        self::sortByName($others, $locale);

        return ['frequent' => $frequent, 'others' => $others];
    }

    public static function name(?string $code, string $locale = 'fr'): ?string
    {
        if ($code === null) {
            return null;
        }

        $names = self::data()['names'][$locale] ?? self::data()['names']['fr'];

        return $names[strtoupper($code)] ?? $code;
    }

    /** Emoji flag from the two letters, so the list reads at a glance. */
    public static function flag(?string $code): string
    {
        if ($code === null || strlen($code) !== 2) {
            return '';
        }

        return implode('', array_map(
            fn (string $letter): string => mb_chr(0x1F1E6 + ord($letter) - ord('A')),
            str_split(strtoupper($code)),
        ));
    }

    /**
     * The currency a shop in that country most likely trades in — or null
     * when the form does not offer it, in which case the form leaves the
     * user's choice alone rather than replacing it with a guess.
     *
     * Only a suggestion: the default used to be MAD for everyone, so a shop in
     * Paris that did not notice the field was provisioned in dirhams.
     */
    public static function currency(string $code): ?string
    {
        return self::data()['currency'][strtoupper($code)] ?? null;
    }

    /** @return list<string> every currency a shop can trade in, frequent first */
    public static function currencyCodes(): array
    {
        return array_values(array_unique(array_merge(self::FREQUENT_CURRENCIES, array_keys(self::data()['currencies']))));
    }

    /** @return array<string, string> code => "Dirham marocain", frequent first */
    public static function currencyOptions(string $locale = 'fr'): array
    {
        $options = [];
        foreach (self::currencyCodes() as $code) {
            $options[$code] = self::currencyName($code, $locale);
        }

        return $options;
    }

    public static function currencyName(string $code, string $locale = 'fr'): string
    {
        $names = self::data()['currencies'][strtoupper($code)]['names'] ?? [];

        return $names[$locale] ?? $names['fr'] ?? strtoupper($code);
    }

    /** The currency's own decimals: 3 for TND (millimes), 0 for XOF, 2 when unknown. */
    public static function currencyDigits(?string $code): int
    {
        return self::data()['currencies'][strtoupper((string) $code)]['digits'] ?? 2;
    }

    /**
     * The timezone a new shop in [$country] runs on: [$hint] (its browser's)
     * when it lies in that country, else the country's main zone.
     */
    public static function timezone(?string $country, ?string $hint = null): string
    {
        $country = strtoupper((string) $country);
        if ($hint) {
            try {
                $zone = new \DateTimeZone($hint);
                $located = strtoupper((string) ($zone->getLocation()['country_code'] ?? ''));
                if ($located === $country || ($located === 'EH' && $country === 'MA')) {
                    return $zone->getName();
                }
            } catch (\Throwable) {
                // Not a zone name: fall back to the country's.
            }
        }
        if (isset(self::MAIN_TIMEZONE[$country])) {
            return self::MAIN_TIMEZONE[$country];
        }
        if (strlen($country) === 2) {
            $zones = \DateTimeZone::listIdentifiers(\DateTimeZone::PER_COUNTRY, $country);
            if ($zones) {
                return $zones[0];
            }
        }

        return 'Africa/Casablanca';
    }

    /** @return array<string, string> code => suggested currency, for the forms' script */
    public static function currencyMap(): array
    {
        return self::data()['currency'];
    }

    /**
     * Alphabetical as a reader of [$locale] expects: "Égypte" with the Es,
     * not after "Zimbabwe" as a plain byte sort would put it.
     *
     * @param  array<string, string>  $names
     */
    private static function sortByName(array &$names, string $locale): void
    {
        if (class_exists(\Collator::class)) {
            $collator = new \Collator($locale);
            uasort($names, fn (string $a, string $b): int => (int) $collator->compare($a, $b));

            return;
        }

        uasort($names, fn (string $a, string $b): int => strcasecmp(Str::ascii($a), Str::ascii($b)));
    }
}
