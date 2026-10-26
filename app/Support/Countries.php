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

    /** The currencies the signup forms offer; suggestions are limited to these. */
    public const OFFERED_CURRENCIES = ['MAD', 'EUR', 'USD', 'XOF', 'DZD', 'TND'];

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
