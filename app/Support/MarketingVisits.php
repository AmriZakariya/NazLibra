<?php

namespace App\Support;

use App\Http\Controllers\Castlit\MarketingController;
use App\Models\MarketingVisit;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\IpUtils;

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
        'business_name', 'activity', 'currency', 'country', 'city', 'address', 'desired_subdomain',
        'contact_name', 'phone', 'email', 'heard_about', 'website',
    ];

    private const BOTS = '/bot|crawl|spider|slurp|preview|facebookexternalhit|embedly|monitor|uptime|pingdom|lighthouse|headless|phantom|curl|wget|python|java\/|go-http|okhttp|axios|node-fetch|scrapy|httpclient|semrush|ahrefs|bingpreview|whatsapp|telegram/i';

    /**
     * Networks that are servers, not people: Meta's (it opens every ad link to
     * check it, with a phone browser's name) and the big clouds' (scanners,
     * scrapers). A real visitor almost never browses from a datacenter.
     */
    public const DATACENTERS = [
        // Meta
        '2a03:2880::/32', '57.141.0.0/16', '31.13.24.0/21', '31.13.64.0/18', '66.220.144.0/20',
        '69.63.176.0/20', '69.171.224.0/19', '157.240.0.0/16', '173.252.64.0/18', '129.134.0.0/16',
        // Tencent Cloud
        '49.51.0.0/16', '170.106.0.0/16', '49.232.0.0/14', '43.128.0.0/10', '129.226.0.0/16', '162.62.0.0/16',
        '119.28.0.0/15', '150.109.0.0/16',
        // Amazon Web Services
        '3.0.0.0/8', '13.32.0.0/12', '52.0.0.0/10', '52.64.0.0/11', '54.0.0.0/8', '18.128.0.0/9',
        // Google Cloud
        '34.0.0.0/8', '35.184.0.0/13', '35.192.0.0/12', '35.208.0.0/12', '35.224.0.0/12',
        // Microsoft Azure
        '20.0.0.0/8', '40.64.0.0/10', '52.96.0.0/11', '52.128.0.0/9',
        // Alibaba Cloud, DigitalOcean, OVH, Hetzner
        '47.74.0.0/15', '47.88.0.0/14', '8.208.0.0/12', '161.35.0.0/16', '164.90.0.0/16', '167.99.0.0/16',
        '146.190.0.0/16', '51.68.0.0/16', '51.75.0.0/16', '51.77.0.0/16', '135.181.0.0/16', '65.108.0.0/16', '95.216.0.0/16',
    ];

    /** A robot by its name: crawlers, link previews, scripts. */
    public static function isBot(string $agent): bool
    {
        return $agent === '' || (bool) preg_match(self::BOTS, $agent);
    }

    public static function fromDatacenter(?string $ip): bool
    {
        return $ip !== null && $ip !== '' && IpUtils::checkIp($ip, self::DATACENTERS);
    }

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

    /** Fields filled from a list on the form: a refused value there was not chosen on the page. */
    private const LIST_FIELDS = ['activity', 'currency', 'country'];

    /**
     * The server refused the sign-up form. Besides the step, says whether the
     * sender looks like a script: it filled the hidden anti-spam field, sent a
     * value no list on the form offers, or never touched a field (the page
     * reports the first touch; a person cannot type without one).
     *
     * @param  list<string>  $fields
     */
    public static function serverRefused(Request $request, array $fields): ?MarketingVisit
    {
        $visit = self::current($request);
        if (! $visit) {
            return null;
        }
        $reason = match (true) {
            in_array('website', $fields, true) => 'honeypot',
            array_intersect(self::LIST_FIELDS, $fields) !== [] => 'impossible',
            MarketingVisit::STAGES[$visit->stage] < MarketingVisit::STAGES[MarketingVisit::STARTED] => 'untouched',
            default => null,
        };
        if ($reason) {
            $visit->robot_reason = $reason;
        }

        return self::mark($visit, MarketingVisit::REFUSED, $fields);
    }

    /** The sign-up was accepted: the visit is sent, flagged if no field was ever touched. */
    public static function serverSent(Request $request, Subscription $subscription): ?MarketingVisit
    {
        $visit = self::current($request);
        if (! $visit) {
            return null;
        }
        if (MarketingVisit::STAGES[$visit->stage] < MarketingVisit::STAGES[MarketingVisit::STARTED] && ! $visit->robot_reason) {
            $visit->robot_reason = 'untouched';
        }

        return self::mark($visit, MarketingVisit::SENT, [], $subscription);
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

    /** The page's language, as MarketingController resolves it. */
    private static function locale(Request $request): string
    {
        return MarketingLocale::resolve($request);
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
        if (self::isBot((string) $request->userAgent())) {
            return false;
        }
        if (self::fromDatacenter(GeoCountry::clientIp($request) ?? $request->ip())) {
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
