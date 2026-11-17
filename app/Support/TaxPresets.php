<?php

namespace App\Support;

/**
 * The taxes a new shop starts with, by its country.
 *
 * Every shop used to start with Morocco's (20 % and 7 %), so a shop in Cairo
 * found a VAT its country does not have and none of the 14 % it charges.
 * These are only a start: the shop edits them in Catalogue › Impôts. The
 * names stay « TVA n% », which the back office translates in every
 * language (« الضريبة على القيمة المضافة 14% »).
 */
final class TaxPresets
{
    /** country => list of [rate, active]; « Sans TVA » (0 %) always comes first. */
    private const RATES = [
        'MA' => [[20, true], [7, false]],
        'EG' => [[14, true], [5, false]],
        'DZ' => [[19, true], [9, false]],
        'TN' => [[19, true], [13, false], [7, false]],
        'FR' => [[20, true], [10, false], [5.5, false]],
        'BE' => [[21, true], [6, false]],
        'SN' => [[18, true]],
        'CI' => [[18, true]],
        'SA' => [[15, true]],
        'AE' => [[5, true]],
    ];

    /**
     * @return list<array{0: string, 1: float|int, 2: bool}> [name, rate, active]
     */
    public static function forCountry(?string $country): array
    {
        $country = strtoupper((string) $country);
        $rates = self::RATES[$country] ?? self::RATES[Countries::DEFAULT];

        $presets = [['Sans TVA', 0, true]];
        foreach ($rates as [$rate, $active]) {
            $label = rtrim(rtrim(number_format($rate, 1, ',', ''), '0'), ',');
            $presets[] = ["TVA {$label}%", $rate, $active];
        }

        return $presets;
    }
}
