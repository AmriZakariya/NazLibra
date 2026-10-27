<?php

namespace App\Support;

use App\Models\Tenant;
use DateTimeZone;
use Throwable;

/**
 * Phone numbers, as the forms now send them: international ("+212612345678").
 *
 * The country-aware work (picker, formatting, the "numéro invalide" warning)
 * happens in the browser and the app, which carry the full numbering plan.
 * The server only tidies: a number that is clearly international is stored
 * without its spaces, anything else is kept as typed — an invalid number
 * warns, it does not block, so it must not be thrown away here either.
 */
final class Phone
{
    /**
     * How a number is split after its country code, where the shops are and
     * the pairs default would read oddly; everywhere else it is in pairs
     * ("+212 6 12 34 56 78"). Keyed by calling code, then by length.
     */
    private const GROUPS = [
        '1' => [10 => [3, 3, 4]],
        '32' => [9 => [3, 2, 2, 2], 8 => [1, 3, 2, 2]],
        '34' => [9 => [3, 2, 2, 2]],
        '216' => [8 => [2, 3, 3]],
        '221' => [9 => [2, 3, 2, 2]],
    ];

    /** @var array<string, array{0: string, 1: string|null}>|null */
    private static ?array $countries = null;

    /** International: a plus or 00, then 8 to 15 digits (E.164). */
    public static function normalize(?string $raw): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', (string) $raw) ?? '');
        if ($value === '') {
            return null;
        }

        if (preg_match('/^(?:\+|00)\s*[0-9][0-9\s().\/-]*$/', $value)) {
            $digits = preg_replace('/\D/', '', str_starts_with($value, '00') ? substr($value, 2) : $value);
            if (strlen($digits) >= 8 && strlen($digits) <= 15) {
                return '+'.$digits;
            }
        }

        return $value;
    }

    /**
     * A number from a form without the phone field's script (an old app, a
     * failed download): "06 12 34 56 78" is read in [$country], the shop's.
     * The online shop used to read every local number as Moroccan, so a Paris
     * customer's "06…" became +212.
     */
    public static function internationalize(?string $raw, string $country): ?string
    {
        $value = self::normalize($raw);
        if ($value === null || str_starts_with($value, '+') || preg_match('/[^0-9\s().\/-]/', $value)) {
            return $value;
        }

        [$dial, $prefix] = self::countries()[strtoupper($country)] ?? [null, null];
        $digits = preg_replace('/\D/', '', $value);
        if ($dial === null || strlen($digits) < 6) {
            return $value;
        }

        if (str_starts_with($digits, $dial) && strlen($digits) >= strlen($dial) + 8) {
            // "212612345678": international, the plus forgotten.
            $international = $digits;
        } else {
            if ($prefix !== null && str_starts_with($digits, $prefix)) {
                $digits = substr($digits, strlen($prefix));
            }
            $international = $dial.$digits;
        }

        return strlen($international) <= 15 ? '+'.$international : $value;
    }

    /**
     * "+212612345678" as people read it: "+212 6 12 34 56 78". Anything not
     * stored international (an old "06 12…", a typo kept on purpose) is shown
     * as it was typed.
     */
    public static function display(?string $value): ?string
    {
        if ($value === null || ! preg_match('/^\+(\d{8,15})$/', $value, $match)) {
            return $value;
        }

        $digits = $match[1];
        // Calling codes are prefix-free: at most one of 1, 2 or 3 digits fits.
        foreach ([1, 2, 3] as $length) {
            $code = substr($digits, 0, $length);
            if (in_array($code, self::dialCodes(), true) && strlen($digits) > $length + 5) {
                $national = substr($digits, $length);

                return '+'.$code.' '.self::group($national, self::GROUPS[$code][strlen($national)] ?? null);
            }
        }

        return $value;
    }

    /** @param  list<int>|null  $sizes */
    private static function group(string $national, ?array $sizes): string
    {
        if ($sizes === null) {
            // Pairs, the odd digit first: 9 digits read "6 12 34 56 78".
            $sizes = array_fill(0, intdiv(strlen($national), 2), 2);
            if (strlen($national) % 2 === 1) {
                array_unshift($sizes, 1);
            }
        }

        $parts = [];
        $offset = 0;
        foreach ($sizes as $size) {
            $parts[] = substr($national, $offset, $size);
            $offset += $size;
        }

        return implode(' ', array_filter($parts, fn (string $part): bool => $part !== ''));
    }

    /** @return array<string, array{0: string, 1: string|null}> ISO => [calling code, national prefix] */
    private static function countries(): array
    {
        return self::$countries ??= require resource_path('data/dial-codes.php');
    }

    /** @return list<string> */
    private static function dialCodes(): array
    {
        return array_values(array_unique(array_column(self::countries(), 0)));
    }

    /**
     * The country a local number ("06 12 34 56 78") is read in: the shop's
     * own. Taken from its address country, else its timezone (Paris → FR),
     * else its currency, else Maroc.
     */
    public static function defaultCountry(?Tenant $tenant): string
    {
        $profile = (array) data_get($tenant?->settings, 'company_profile', []);

        foreach ([$profile['country_code'] ?? null, $profile['country'] ?? null] as $candidate) {
            if ($code = self::countryCode($candidate)) {
                return $code;
            }
        }

        $timezone = (string) ($tenant?->timezone ?: ($profile['timezone'] ?? ''));
        if ($timezone !== '') {
            try {
                $code = (new DateTimeZone($timezone))->getLocation()['country_code'] ?? null;
                if ($code = self::countryCode($code)) {
                    return $code;
                }
            } catch (Throwable) {
                // An unknown zone name: fall through to the currency.
            }
        }

        $currency = Money::normalizeCode($tenant?->currency ?: ($profile['currency'] ?? null));
        foreach (Countries::FREQUENT as $code) {
            if (Countries::currency($code) === $currency) {
                return $code;
            }
        }

        return Countries::DEFAULT;
    }

    /** "FR", "fr", "France" or "Maroc" → an accepted ISO code; null otherwise. */
    private static function countryCode(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $upper = strtoupper($value);
        // Laâyoune's timezone reports EH, which the country list leaves out.
        if ($upper === 'EH') {
            return Countries::DEFAULT;
        }
        if (strlen($upper) === 2 && in_array($upper, Countries::codes(), true)) {
            return $upper;
        }

        $needle = mb_strtolower($value);
        foreach (['fr', 'en', 'ar'] as $locale) {
            foreach (Countries::localized($locale) as $code => $name) {
                if (mb_strtolower($name) === $needle) {
                    return $code;
                }
            }
        }

        return null;
    }
}
