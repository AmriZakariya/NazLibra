<?php

namespace App\Support;

/**
 * Writes a money amount out in French words.
 *
 * Moroccan invoices are expected to carry the total in letters ("Arrêtée la
 * présente facture à la somme de…"); a figure alone is trivially altered on a
 * paper copy, which is the whole point of the line.
 */
class AmountInWords
{
    private const UNITS = [
        0 => 'zéro', 1 => 'un', 2 => 'deux', 3 => 'trois', 4 => 'quatre', 5 => 'cinq',
        6 => 'six', 7 => 'sept', 8 => 'huit', 9 => 'neuf', 10 => 'dix', 11 => 'onze',
        12 => 'douze', 13 => 'treize', 14 => 'quatorze', 15 => 'quinze', 16 => 'seize',
    ];

    private const TENS = [
        2 => 'vingt', 3 => 'trente', 4 => 'quarante', 5 => 'cinquante',
        6 => 'soixante', 7 => 'soixante', 8 => 'quatre-vingt', 9 => 'quatre-vingt',
    ];

    /** Currency code => [singular major, plural major, singular minor, plural minor]. */
    private const CURRENCIES = [
        'MAD' => ['dirham', 'dirhams', 'centime', 'centimes'],
        'EUR' => ['euro', 'euros', 'centime', 'centimes'],
        'USD' => ['dollar', 'dollars', 'cent', 'cents'],
        'XOF' => ['franc CFA', 'francs CFA', 'centime', 'centimes'],
        'DZD' => ['dinar', 'dinars', 'centime', 'centimes'],
        'TND' => ['dinar', 'dinars', 'millime', 'millimes'],
        'CHF' => ['franc', 'francs', 'centime', 'centimes'],
        'CAD' => ['dollar', 'dollars', 'cent', 'cents'],
    ];

    /**
     * Currency => locale => [major, minor], for the languages other than
     * French; a currency missing here is written by its code.
     */
    private const NAMES = [
        'EGP' => ['ar' => ['جنيه مصري', 'قرش'], 'en' => ['Egyptian pounds', 'piastres'], 'es' => ['libras egipcias', 'piastras'], 'pt' => ['libras egípcias', 'piastras'], 'de' => ['ägyptische Pfund', 'Piaster'], 'it' => ['sterline egiziane', 'piastre']],
        'MAD' => ['ar' => ['درهم', 'سنتيم'], 'en' => ['dirhams', 'centimes'], 'es' => ['dírhams', 'céntimos'], 'pt' => ['dirhams', 'cêntimos'], 'de' => ['Dirham', 'Centimes'], 'it' => ['dirham', 'centesimi']],
        'EUR' => ['ar' => ['يورو', 'سنت'], 'en' => ['euros', 'cents'], 'es' => ['euros', 'céntimos'], 'pt' => ['euros', 'cêntimos'], 'de' => ['Euro', 'Cent'], 'it' => ['euro', 'centesimi']],
        'USD' => ['ar' => ['دولار', 'سنت'], 'en' => ['US dollars', 'cents'], 'es' => ['dólares', 'centavos'], 'pt' => ['dólares', 'cêntimos'], 'de' => ['US-Dollar', 'Cent'], 'it' => ['dollari', 'centesimi']],
        'SAR' => ['ar' => ['ريال سعودي', 'هللة'], 'en' => ['Saudi riyals', 'halalas']],
        'AED' => ['ar' => ['درهم إماراتي', 'فلس'], 'en' => ['UAE dirhams', 'fils']],
        'DZD' => ['ar' => ['دينار جزائري', 'سنتيم'], 'en' => ['Algerian dinars', 'centimes']],
        'TND' => ['ar' => ['دينار تونسي', 'مليم'], 'en' => ['Tunisian dinars', 'millimes'], 'es' => ['dinares tunecinos', 'milésimos'], 'pt' => ['dinares tunisinos', 'milésimos'], 'de' => ['tunesische Dinar', 'Millimes'], 'it' => ['dinari tunisini', 'millesimi']],
    ];

    /**
     * The amount in words, in a document's language: French by hand (the
     * invoice wording Moroccan shops know), any other language through intl,
     * Arabic as Egyptian invoices write it (« فقط … جنيه مصري لا غير »).
     * Without intl, a foreign language falls back on the French.
     */
    public static function money(float|string $amount, string $currency = 'MAD', ?string $locale = null): string
    {
        $locale = substr((string) $locale, 0, 2);
        if ($locale !== '' && $locale !== 'fr' && class_exists(\NumberFormatter::class)) {
            return self::spelled((float) $amount, strtoupper($currency), $locale);
        }

        return self::french($amount, $currency);
    }

    private static function spelled(float $amount, string $currency, string $locale): string
    {
        $scale = self::minorScale($currency);
        $units = (int) round(abs($amount) * $scale);
        $major = intdiv($units, $scale);
        $minor = $units % $scale;
        $spell = new \NumberFormatter($locale, \NumberFormatter::SPELLOUT);
        [$majorName, $minorName] = self::NAMES[$currency][$locale] ?? self::NAMES[$currency]['en'] ?? [$currency, ''];

        $words = $spell->format($major).' '.$majorName;
        if ($minor > 0) {
            $joiner = match ($locale) {
                'ar' => ' و',
                'en' => ' and ',
                'es' => ' con ',
                'pt' => ' e ',
                'de' => ' und ',
                'it' => ' e ',
                default => ' ',
            };
            $words .= $joiner.$spell->format($minor).($minorName !== '' ? ' '.$minorName : '');
        }
        if ($amount < 0) {
            $words = '- '.$words;
        }

        return $locale === 'ar' ? 'فقط '.$words.' لا غير' : mb_strtoupper(mb_substr($words, 0, 1)).mb_substr($words, 1);
    }

    private static function french(float|string $amount, string $currency = 'MAD'): string
    {
        // Through cents, so 0.1 + 0.2 arithmetic upstream cannot turn 145.30
        // into "cent quarante-cinq dirhams et vingt-neuf centimes".
        // Millimes for the dinar: 12,345 DT is « douze dinars et trois cent
        // quarante-cinq millimes », not 35 centimes.
        $scale = self::minorScale($currency);
        $units = (int) round((float) $amount * $scale);
        $negative = $units < 0;
        $units = abs($units);
        $major = intdiv($units, $scale);
        $minor = $units % $scale;

        [$one, $many, $minorOne, $minorMany] = self::CURRENCIES[strtoupper($currency)]
            ?? [strtoupper($currency), strtoupper($currency), 'centime', 'centimes'];

        // "zéro dirham" and "un dirham" are both singular in French.
        $words = self::integer($major).' '.($major <= 1 ? $one : $many);
        if ($minor > 0) {
            $words .= ' et '.self::integer($minor).' '.($minor === 1 ? $minorOne : $minorMany);
        }

        return ucfirst(($negative ? 'moins ' : '').$words);
    }

    /** Minor units per major one: 1000 for TND (millimes), 100 otherwise. */
    private static function minorScale(string $currency): int
    {
        // A plain list, not Countries: this class runs in unit tests with no app.
        return in_array(strtoupper($currency), ['TND', 'LYD', 'KWD', 'BHD', 'OMR', 'JOD', 'IQD'], true) ? 1000 : 100;
    }

    public static function integer(int $number): string
    {
        if ($number < 0) {
            return 'moins '.self::integer(-$number);
        }
        if ($number < 17) {
            return self::UNITS[$number];
        }
        if ($number < 100) {
            return self::underHundred($number);
        }
        if ($number < 1000) {
            return self::underThousand($number);
        }

        foreach ([1_000_000_000 => ['milliard', 'milliards'], 1_000_000 => ['million', 'millions'], 1000 => ['mille', 'mille']] as $scale => [$one, $many]) {
            if ($number < $scale) {
                continue;
            }

            $count = intdiv($number, $scale);
            $rest = $number % $scale;
            // "mille" never takes an s and never takes "un" in front of it;
            // "million" and "milliard" are nouns and do both.
            $head = $scale === 1000
                ? ($count === 1 ? 'mille' : self::integer($count).' mille')
                : self::integer($count).' '.($count === 1 ? $one : $many);

            return $rest === 0 ? $head : $head.' '.self::integer($rest);
        }

        return self::UNITS[0];
    }

    private static function underHundred(int $number): string
    {
        $tens = intdiv($number, 10);
        $unit = $number % 10;

        // Seventy and ninety are counted in twenties in French: 71 is
        // "soixante et onze", 91 is "quatre-vingt-onze".
        if ($tens === 1) {
            // 17-19 are built on ten, not on a tens word of their own.
            return 'dix-'.self::UNITS[$unit];
        }

        if ($tens === 7 || $tens === 9) {
            $remainder = $number - ($tens === 7 ? 60 : 80);
            $base = self::TENS[$tens];
            // 71 keeps the "et" of 61; 91 does not, because 81 does not.
            if ($remainder === 11 && $tens === 7) {
                return $base.' et onze';
            }

            return $base.'-'.self::integer($remainder);
        }

        if ($unit === 0) {
            // "quatre-vingts" takes its s only when nothing follows it.
            return $tens === 8 ? 'quatre-vingts' : self::TENS[$tens];
        }

        if ($unit === 1 && $tens !== 8) {
            return self::TENS[$tens].' et un';
        }

        return self::TENS[$tens].'-'.self::UNITS[$unit];
    }

    private static function underThousand(int $number): string
    {
        $hundreds = intdiv($number, 100);
        $rest = $number % 100;
        // Same rule as quatre-vingts: "deux cents", but "deux cent dix".
        $head = $hundreds === 1 ? 'cent' : self::UNITS[$hundreds].($rest === 0 ? ' cents' : ' cent');

        return $rest === 0 ? $head : $head.' '.self::integer($rest);
    }
}
