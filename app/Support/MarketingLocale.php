<?php

namespace App\Support;

use App\Http\Controllers\Castlit\MarketingController;
use Illuminate\Http\Request;

/**
 * The language of the castlitpos.com pages for a visitor:
 *
 *  1. their own choice: ?lang= in the link or the FR / ع / EN switcher,
 *     remembered in the session;
 *  2. their browser's languages, in their order, the first one we speak:
 *     the surest signal (a French speaker in Dubai reads French);
 *  3. their country, from the IP: Arabic in the Arab world, French in the
 *     francophone countries, Morocco included;
 *  4. English.
 *
 * Search engines always get French at the bare address: the hreflang links
 * say /?lang=ar and /?lang=en are the other languages, and a crawler from the
 * US served English at / would index English under the French address.
 */
final class MarketingLocale
{
    public const SESSION_KEY = 'castlit_lang';

    public const FALLBACK = 'en';

    /** The language the bare address carries for search engines. */
    public const CRAWLER = 'fr';

    private const ARABIC = ['AE', 'SA', 'QA', 'KW', 'BH', 'OM', 'EG', 'JO', 'IQ', 'LY', 'YE', 'SD', 'SY', 'LB', 'PS'];

    private const FRENCH = [
        'MA', 'DZ', 'TN', 'MR', 'FR', 'BE', 'CH', 'LU', 'MC', 'SN', 'CI', 'ML', 'BF', 'NE', 'TG', 'BJ', 'GN',
        'CM', 'GA', 'CG', 'CD', 'CF', 'TD', 'MG', 'DJ', 'KM', 'HT', 'RE', 'GP', 'MQ', 'GF', 'NC', 'PF',
    ];

    public static function resolve(Request $request): string
    {
        $chosen = $request->query('lang');
        if (self::speaks($chosen)) {
            if ($request->hasSession()) {
                $request->session()->put(self::SESSION_KEY, $chosen);
            }

            return $chosen;
        }

        $remembered = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;
        if (self::speaks($remembered)) {
            return $remembered;
        }

        if (MarketingVisits::isBot((string) $request->userAgent())) {
            return self::CRAWLER;
        }

        return self::fromBrowser((string) $request->header('Accept-Language', ''))
            ?? self::fromCountry(GeoCountry::fromIp(GeoCountry::clientIp($request)))
            ?? self::FALLBACK;
    }

    /** The first language we speak among the browser's, in its order of preference. */
    public static function fromBrowser(string $header): ?string
    {
        $ranked = [];
        foreach (explode(',', $header) as $i => $part) {
            $bits = explode(';', trim($part));
            $tag = strtolower(trim($bits[0]));
            if ($tag === '' || $tag === '*') {
                continue;
            }
            $q = 1.0;
            foreach (array_slice($bits, 1) as $param) {
                if (preg_match('/^\s*q\s*=\s*([0-9.]+)\s*$/', $param, $m)) {
                    $q = (float) $m[1];
                }
            }
            if ($q > 0) {
                $ranked[] = [$q, -$i, substr($tag, 0, 2)];
            }
        }
        rsort($ranked);

        foreach ($ranked as [, , $primary]) {
            if (self::speaks($primary)) {
                return $primary;
            }
        }

        return null;
    }

    public static function fromCountry(?string $country): ?string
    {
        $country = strtoupper((string) $country);

        return match (true) {
            in_array($country, self::ARABIC, true) => 'ar',
            in_array($country, self::FRENCH, true) => 'fr',
            default => null,
        };
    }

    private static function speaks(mixed $lang): bool
    {
        return is_string($lang) && in_array($lang, MarketingController::LOCALES, true);
    }
}
