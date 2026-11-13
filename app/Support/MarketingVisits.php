<?php

namespace App\Support;

use App\Http\Controllers\Castlit\MarketingController;
use App\Models\MarketingVisit;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Who visits the castlitpos.com landing page and how far they get: the page,
 * the form, a submit, a refusal, a request sent. One row per visit (the
 * session the site already opens; no cookie of its own), on the master only,
 * kept KEEP_DAYS days.
 */
final class MarketingVisits
{
    public const SESSION_KEY = 'castlit_visit';

    public const KEEP_DAYS = 90;

    /** Form fields a refusal may name; anything else sent by the page is dropped. */
    public const FIELDS = [
        'business_name', 'activity', 'currency', 'country', 'city', 'desired_subdomain',
        'contact_name', 'phone', 'email', 'heard_about', 'website',
    ];

    private const BOTS = '/bot|crawl|spider|slurp|preview|facebookexternalhit|embedly|monitor|uptime|pingdom|lighthouse|headless|phantom|curl|wget|python|java\/|go-http|okhttp|axios|node-fetch|scrapy|httpclient|semrush|ahrefs|bingpreview|whatsapp|telegram/i';

    /** The landing page was opened: start a visit, or keep the session's one. */
    public static function open(Request $request): ?MarketingVisit
    {
        if (! self::countable($request)) {
            return null;
        }

        $visit = self::current($request);
        $touch = CampaignAttribution::current($request);

        if ($visit) {
            $visit->last_seen_at = now();
            // A tagged visit takes over a direct one, as in CampaignAttribution.
            if ($touch && $visit->campaign_source === CampaignAttribution::DIRECT && ($touch['source'] ?? null) !== CampaignAttribution::DIRECT) {
                $visit->fill(self::campaignColumns($touch));
            }
            $visit->save();

            return $visit;
        }

        $agent = (string) $request->userAgent();
        $visit = MarketingVisit::create([
            'ip' => GeoCountry::clientIp($request) ?? $request->ip(),
            'country' => GeoCountry::guess($request),
            'locale' => self::locale($request),
            'device' => self::device($agent),
            'user_agent' => Str::limit($agent, 250, ''),
            'stage' => MarketingVisit::OPENED,
            'last_seen_at' => now(),
        ] + self::campaignColumns($touch));
        $request->session()->put(self::SESSION_KEY, $visit->id);

        return $visit;
    }

    /**
     * The visit moved on: the form was started, a submit tried, refused (with
     * the fields at fault) or sent. A visit never moves back, except that a
     * refused one can still be sent.
     *
     * @param  list<string>  $fields
     */
    public static function mark(Request|MarketingVisit|null $from, string $stage, array $fields = [], ?Subscription $subscription = null): ?MarketingVisit
    {
        if (! isset(MarketingVisit::STAGES[$stage])) {
            return null;
        }
        $visit = $from instanceof Request ? self::current($from) : $from;
        if (! $visit) {
            return null;
        }

        if (MarketingVisit::STAGES[$stage] >= MarketingVisit::STAGES[$visit->stage] || $stage === MarketingVisit::SENT) {
            $visit->stage = $stage;
        }
        if ($stage === MarketingVisit::REFUSED) {
            $fields = array_values(array_intersect(self::FIELDS, $fields));
            $visit->refused_fields = $fields ? Str::limit(implode(',', $fields), 200, '') : null;
        }
        if ($subscription) {
            $visit->subscription_id = $subscription->id;
        }
        $visit->last_seen_at = now();
        $visit->save();

        return $visit;
    }

    /**
     * What the page sends back with a step: the visit's id, signed, so the
     * step can be recorded without opening the session (a step sent while
     * the form posts would otherwise race it for the session and could drop
     * what the « merci » page reads).
     */
    public static function token(MarketingVisit $visit): string
    {
        return $visit->id.'.'.substr(hash_hmac('sha256', 'marketing-visit:'.$visit->id, (string) config('app.key')), 0, 24);
    }

    public static function fromToken(?string $token): ?MarketingVisit
    {
        [$id, $sig] = array_pad(explode('.', (string) $token, 2), 2, '');
        if (! ctype_digit($id) || $sig === '') {
            return null;
        }
        $expected = substr(hash_hmac('sha256', 'marketing-visit:'.$id, (string) config('app.key')), 0, 24);

        return hash_equals($expected, $sig) ? MarketingVisit::find((int) $id) : null;
    }

    public static function current(Request $request): ?MarketingVisit
    {
        if (! $request->hasSession()) {
            return null;
        }
        $id = $request->session()->get(self::SESSION_KEY);

        return $id ? MarketingVisit::find($id) : null;
    }

    /** Visits older than KEEP_DAYS are deleted. */
    public static function prune(): int
    {
        return MarketingVisit::where('created_at', '<', now()->subDays(self::KEEP_DAYS))->delete();
    }

    /** The page's language, as MarketingController resolves it (?lang=, then the session). */
    private static function locale(Request $request): string
    {
        $lang = $request->query('lang');
        if (! in_array($lang, MarketingController::LOCALES, true)) {
            $lang = $request->session()->get('castlit_lang', 'fr');
        }

        return in_array($lang, MarketingController::LOCALES, true) ? $lang : 'fr';
    }

    public static function device(string $agent): string
    {
        return match (true) {
            (bool) preg_match('/ipad|tablet|kindle|silk|android(?!.*mobile)/i', $agent) => 'tablet',
            (bool) preg_match('/mobi|iphone|ipod|android|windows phone/i', $agent) => 'phone',
            default => 'computer',
        };
    }

    /** A person on the marketing site: not a bot, a link preview, a prefetch, or the team. */
    private static function countable(Request $request): bool
    {
        if (! config('castlit.is_master') || ! $request->hasSession() || ! $request->isMethod('GET')) {
            return false;
        }
        $agent = (string) $request->userAgent();
        if ($agent === '' || preg_match(self::BOTS, $agent)) {
            return false;
        }
        $purpose = Str::lower((string) ($request->header('Sec-Purpose') ?? $request->header('Purpose') ?? ''));
        if (str_contains($purpose, 'prefetch') || str_contains($purpose, 'prerender')) {
            return false;
        }

        return ! (bool) $request->user()?->is_platform_admin;
    }

    /** @return array<string, string|null> */
    private static function campaignColumns(?array $touch): array
    {
        return [
            'campaign_source' => $touch['source'] ?? null,
            'campaign_medium' => $touch['medium'] ?? null,
            'campaign_name' => $touch['campaign'] ?? null,
            'referrer' => isset($touch['referrer']) ? Str::limit((string) $touch['referrer'], 120, '') : null,
            'landing' => isset($touch['landing']) ? Str::limit((string) $touch['landing'], 120, '') : null,
        ];
    }
}
