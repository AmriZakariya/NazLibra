<?php

namespace App\Support;

use Illuminate\Http\Request;
use MaxMind\Db\Reader;

/**
 * The country a visitor is most likely signing up from — a pre-selection,
 * never a decision: the form still lets them pick another.
 *
 * In order: the IP address against a local copy of DB-IP's country table,
 * then the region of the browser's preferred language ("fr-MA"). Anything
 * the signup does not offer counts as unknown, and the caller falls back.
 */
class GeoCountry
{
    private static ?Reader $reader = null;

    private static bool $opened = false;

    /** @var (\Closure(string): ?string)|null stands in for the database in tests */
    private static ?\Closure $lookup = null;

    public static function fake(?\Closure $lookup): void
    {
        self::$lookup = $lookup;
    }

    /** ISO code the form offers, or null when nothing says. */
    public static function guess(Request $request): ?string
    {
        return self::fromIp(self::clientIp($request))
            ?? self::fromAcceptLanguage((string) $request->header('Accept-Language', ''));
    }

    public static function fromIp(?string $ip): ?string
    {
        if (! $ip || ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null;
        }
        if (self::$lookup) {
            return self::offered((self::$lookup)($ip));
        }
        $reader = self::reader();
        if (! $reader) {
            return null;
        }

        try {
            $record = $reader->get($ip);
        } catch (\Throwable) {
            return null;
        }

        return self::offered($record['country']['iso_code'] ?? null);
    }

    /** "fr-MA,fr;q=0.9,en;q=0.8" → MA. A language without a region says nothing. */
    public static function fromAcceptLanguage(string $header): ?string
    {
        foreach (explode(',', $header) as $part) {
            $tag = trim(explode(';', $part)[0]);
            if (preg_match('/^[a-z]{2,3}[-_]([A-Za-z]{2})$/', $tag, $m) && ($code = self::offered($m[1]))) {
                return $code;
            }
        }

        return null;
    }

    /**
     * The visitor's address. When the request reaches PHP through the host's
     * own proxy (an internal address), the forwarded one is the visitor's.
     * Trusting it is harmless here: someone forging it only changes their
     * own form's default.
     */
    public static function clientIp(Request $request): ?string
    {
        $direct = $request->server('REMOTE_ADDR');
        if ($direct && filter_var($direct, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return $direct;
        }

        foreach (['X-Forwarded-For', 'X-Real-IP', 'CF-Connecting-IP'] as $header) {
            foreach (explode(',', (string) $request->header($header, '')) as $candidate) {
                $candidate = trim($candidate);
                if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $candidate;
                }
            }
        }

        return $direct ?: null;
    }

    /**
     * Timezone → country, for the zones of the countries the form offers.
     *
     * @return array<string, string>
     */
    public static function timezoneMap(): array
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach (\DateTimeZone::listIdentifiers() as $zone) {
                $code = self::offered((new \DateTimeZone($zone))->getLocation()['country_code'] ?? null);
                if ($code) {
                    $map[$zone] = $code;
                }
            }
        }

        return $map;
    }

    /** For tests: forget the opened database so another file can be read. */
    public static function reset(): void
    {
        self::$reader?->close();
        self::$reader = null;
        self::$opened = false;
    }

    private static function reader(): ?Reader
    {
        if (! self::$opened) {
            self::$opened = true;
            $path = (string) config('castlit.geoip.path');
            try {
                self::$reader = is_file($path) ? new Reader($path) : null;
            } catch (\Throwable) {
                self::$reader = null;
            }
        }

        return self::$reader;
    }

    private static function offered(?string $code): ?string
    {
        $code = strtoupper((string) $code);
        // Western Sahara is listed under Morocco everywhere else in the app.
        $code = $code === 'EH' ? 'MA' : $code;

        return in_array($code, Countries::codes(), true) ? $code : null;
    }
}
