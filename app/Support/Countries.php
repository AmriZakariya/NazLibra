<?php

namespace App\Support;

/**
 * The countries a subscription can come from, by ISO 3166-1 alpha-2 code.
 *
 * Not the whole world: the markets Castl-it-POS actually sells into, Morocco
 * first because it is the default and the home market, then the francophone
 * and nearby markets where requests already arrive from. A code missing here
 * is a validation error rather than free text, so the list stays countable.
 */
final class Countries
{
    public const DEFAULT = 'MA';

    /** @return array<string, string> code => French name, in display order */
    public static function all(): array
    {
        return [
            'MA' => 'Maroc',
            'DZ' => 'Algérie',
            'TN' => 'Tunisie',
            'MR' => 'Mauritanie',
            'SN' => 'Sénégal',
            'CI' => 'Côte d’Ivoire',
            'ML' => 'Mali',
            'BF' => 'Burkina Faso',
            'CM' => 'Cameroun',
            'GA' => 'Gabon',
            'CD' => 'RD Congo',
            'FR' => 'France',
            'BE' => 'Belgique',
            'CH' => 'Suisse',
            'LU' => 'Luxembourg',
            'ES' => 'Espagne',
            'IT' => 'Italie',
            'DE' => 'Allemagne',
            'NL' => 'Pays-Bas',
            'GB' => 'Royaume-Uni',
            'CA' => 'Canada',
            'US' => 'États-Unis',
            'AE' => 'Émirats arabes unis',
            'SA' => 'Arabie saoudite',
            'QA' => 'Qatar',
            'EG' => 'Égypte',
        ];
    }

    /**
     * The same list, named in [$locale] — the signup form is shown in French,
     * Arabic and English. Uses intl when the host has it and falls back to
     * the French names, so a shared host without the extension still shows a
     * usable list rather than bare codes.
     *
     * @return array<string, string>
     */
    public static function localized(string $locale): array
    {
        $names = self::all();

        if ($locale === 'fr' || ! class_exists(\Locale::class)) {
            return $names;
        }

        foreach ($names as $code => $french) {
            $translated = \Locale::getDisplayRegion('-'.$code, $locale);
            if (is_string($translated) && $translated !== '' && $translated !== $code) {
                $names[$code] = $translated;
            }
        }

        return $names;
    }

    /**
     * The currency a shop in that country most likely trades in.
     *
     * Only a suggestion for the form: the default was MAD for everyone, so a
     * shop in Paris that did not notice the field was provisioned in dirhams.
     */
    public static function currency(string $code): string
    {
        return match (strtoupper($code)) {
            'MA' => 'MAD',
            'DZ' => 'DZD',
            'TN' => 'TND',
            'SN', 'CI', 'ML', 'BF' => 'XOF',
            'FR', 'BE', 'LU', 'ES', 'IT', 'DE', 'NL' => 'EUR',
            'US' => 'USD',
            default => 'USD',
        };
    }

    /** @return array<string, string> code => suggested currency, for the forms' script */
    public static function currencyMap(): array
    {
        return array_combine(self::codes(), array_map(self::currency(...), self::codes()));
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::all());
    }

    public static function name(?string $code): ?string
    {
        return $code === null ? null : (self::all()[strtoupper($code)] ?? $code);
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
}
