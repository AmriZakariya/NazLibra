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
    ];

    public static function money(float|string $amount, string $currency = 'MAD'): string
    {
        // Through cents, so 0.1 + 0.2 arithmetic upstream cannot turn 145.30
        // into "cent quarante-cinq dirhams et vingt-neuf centimes".
        $cents = (int) round((float) $amount * 100);
        $negative = $cents < 0;
        $cents = abs($cents);
        $major = intdiv($cents, 100);
        $minor = $cents % 100;

        [$one, $many, $minorOne, $minorMany] = self::CURRENCIES[strtoupper($currency)]
            ?? [strtoupper($currency), strtoupper($currency), 'centime', 'centimes'];

        // "zéro dirham" and "un dirham" are both singular in French.
        $words = self::integer($major).' '.($major <= 1 ? $one : $many);
        if ($minor > 0) {
            $words .= ' et '.self::integer($minor).' '.($minor === 1 ? $minorOne : $minorMany);
        }

        return ucfirst(($negative ? 'moins ' : '').$words);
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
