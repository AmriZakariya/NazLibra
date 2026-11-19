<?php

namespace App\Support;

/** Small helpers for stock numbers that may be fractional (bottles used by the ml). */
final class Stock
{
    /** A whole number when it is one, else the number to four decimals. */
    public static function number(mixed $value): int|float
    {
        $n = round((float) $value, 4);

        return abs($n - round($n)) < 0.00005 ? (int) round($n) : $n;
    }

    /** « 2,95 » / « 3 »: at most four decimals, no trailing zeros, the shop's separator. */
    public static function plain(mixed $value, string $decimal = ','): string
    {
        $text = rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');

        return $decimal === '.' ? $text : str_replace('.', $decimal, $text);
    }

    /** For tables: thousands separated, decimals only when the stock has some. */
    public static function display(mixed $value): string
    {
        $n = self::number($value);
        if (is_int($n)) {
            return number_format($n, 0, ',', ' ');
        }

        return rtrim(rtrim(number_format($n, 4, ',', ' '), '0'), ',');
    }
}
