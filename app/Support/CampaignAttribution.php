<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Which ad, post or search brought a visitor, kept until they sign up.
 *
 * Held in the session the site already opens: no cookie of its own, so no
 * consent banner. The price is the session's lifetime: a visitor who comes
 * back the next day to sign up counts as direct.
 *
 * The first touch wins: a second ad clicked in the same visit does not take
 * the credit from the first. The one exception is "direct" (no tags, no
 * referring site), which any tagged visit replaces: a visitor who typed the
 * address and then clicked an ad was brought by the ad.
 */
final class CampaignAttribution
{
    public const SESSION_KEY = 'castlit_campaign';

    public const DIRECT = 'direct';

    /** Known referring sites, by the end of their host name. */
    private const REFERRERS = [
        'google.' => ['google', 'organic'],
        'bing.com' => ['bing', 'organic'],
        'duckduckgo.com' => ['duckduckgo', 'organic'],
        'yahoo.com' => ['yahoo', 'organic'],
        'facebook.com' => ['facebook', 'social'],
        'fb.com' => ['facebook', 'social'],
        'instagram.com' => ['instagram', 'social'],
        'tiktok.com' => ['tiktok', 'social'],
        'youtube.com' => ['youtube', 'social'],
        'linkedin.com' => ['linkedin', 'social'],
        'lnkd.in' => ['linkedin', 'social'],
        't.co' => ['x', 'social'],
        'twitter.com' => ['x', 'social'],
        'x.com' => ['x', 'social'],
        'whatsapp.com' => ['whatsapp', 'social'],
        'snapchat.com' => ['snapchat', 'social'],
    ];

    /** Remember where this visit came from, unless an earlier touch already says. */
    public static function capture(Request $request): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $touch = self::fromRequest($request);
        if ($touch === null) {
            return;  // a page of our own linking to another: not a new arrival
        }

        $known = $request->session()->get(self::SESSION_KEY);
        if (is_array($known) && ($known['source'] ?? null) !== self::DIRECT) {
            return;
        }
        if (is_array($known) && $touch['source'] === self::DIRECT) {
            return;
        }

        $request->session()->put(self::SESSION_KEY, $touch);
    }

    /**
     * What this one request says about its origin, or null when it comes from
     * one of our own pages.
     *
     * @return array<string, string|null>|null
     */
    public static function fromRequest(Request $request): ?array
    {
        $utm = [
            'source' => self::clean($request->query('utm_source'), 60, true),
            'medium' => self::clean($request->query('utm_medium'), 60, true),
            'campaign' => self::clean($request->query('utm_campaign'), 120),
            'content' => self::clean($request->query('utm_content'), 120),
            'term' => self::clean($request->query('utm_term'), 120),
        ];

        $click = match (true) {
            filled($request->query('gclid')) => 'gclid',
            filled($request->query('gbraid')), filled($request->query('wbraid')) => 'gclid',
            filled($request->query('fbclid')) => 'fbclid',
            filled($request->query('ttclid')) => 'ttclid',
            default => null,
        };

        $host = self::referrerHost($request);
        if ($host !== null && self::isOwnHost($host)) {
            $host = null;
            if ($utm['source'] === null && $click === null) {
                return null;
            }
        }

        [$source, $medium] = match (true) {
            $utm['source'] !== null => [$utm['source'], $utm['medium']],
            $click === 'gclid' => ['google', 'cpc'],
            $click === 'fbclid' => ['facebook', 'social'],
            $click === 'ttclid' => ['tiktok', 'cpc'],
            $host !== null => self::known($host),
            default => [self::DIRECT, null],
        };

        return [
            'source' => $source,
            'medium' => $medium ?? $utm['medium'],
            'campaign' => $utm['campaign'],
            'content' => $utm['content'],
            'term' => $utm['term'],
            'click_id' => $click,
            'referrer' => $host,
            'landing' => Str::limit('/'.ltrim($request->path(), '/'), 120, ''),
            'at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    /** The visit's origin as remembered in the session, if any. */
    public static function current(Request $request): ?array
    {
        if (! $request->hasSession()) {
            return null;
        }
        $touch = $request->session()->get(self::SESSION_KEY);

        return is_array($touch) ? $touch : null;
    }

    /**
     * Columns and meta for a new subscription request.
     *
     * @return array{columns: array<string, string|null>, meta: array<string, string|null>|null}
     */
    public static function forSubscription(Request $request): array
    {
        $touch = self::current($request) ?? self::fromRequest($request);
        if ($touch === null) {
            return ['columns' => ['campaign_source' => null, 'campaign_medium' => null, 'campaign_name' => null], 'meta' => null];
        }

        return [
            'columns' => [
                'campaign_source' => $touch['source'] ?? null,
                'campaign_medium' => $touch['medium'] ?? null,
                'campaign_name' => $touch['campaign'] ?? null,
            ],
            'meta' => array_filter([
                'content' => $touch['content'] ?? null,
                'term' => $touch['term'] ?? null,
                'click_id' => $touch['click_id'] ?? null,
                'referrer' => $touch['referrer'] ?? null,
                'landing' => $touch['landing'] ?? null,
                'at' => $touch['at'] ?? null,
            ], fn ($v) => $v !== null && $v !== ''),
        ];
    }

    /**
     * Google Play's `referrer` value, so installs show per campaign in the
     * Play Console. Null for a visit with nothing to pass on.
     */
    public static function playReferrer(?array $touch): ?string
    {
        if ($touch === null || in_array($touch['source'] ?? null, [null, self::DIRECT], true)) {
            return null;
        }

        return http_build_query(array_filter([
            'utm_source' => $touch['source'] ?? null,
            'utm_medium' => $touch['medium'] ?? null,
            'utm_campaign' => $touch['campaign'] ?? null,
            'utm_content' => $touch['content'] ?? null,
            'utm_term' => $touch['term'] ?? null,
        ], fn ($v) => $v !== null && $v !== ''), '', '&', PHP_QUERY_RFC3986);
    }

    /** Add the campaign to a Play Store listing link. */
    public static function withPlayReferrer(string $url, ?array $touch): string
    {
        $referrer = self::playReferrer($touch);
        if ($referrer === null || ! str_contains($url, 'play.google.com')) {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').'referrer='.rawurlencode($referrer);
    }

    /**
     * A tag as typed in an ad manager, made safe to store and show: letters,
     * digits and a few separators, trimmed. Sources and mediums are lowercased
     * so "Facebook" and "facebook" count as one.
     */
    private static function clean(mixed $value, int $max, bool $lower = false): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = preg_replace('/[^\p{L}\p{N} ._+\-\/:|]/u', '', $value) ?? '';
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        if ($value === '') {
            return null;
        }
        $value = Str::limit($value, $max, '');

        return $lower ? Str::lower($value) : $value;
    }

    private static function referrerHost(Request $request): ?string
    {
        $referer = (string) $request->headers->get('referer', '');
        $host = $referer !== '' ? parse_url($referer, PHP_URL_HOST) : null;
        if (! is_string($host) || $host === '') {
            return null;
        }
        $host = Str::lower($host);

        return Str::startsWith($host, 'www.') ? substr($host, 4) : $host;
    }

    private static function isOwnHost(string $host): bool
    {
        $main = Str::lower((string) config('castlit.main_domain'));
        $self = Str::lower((string) request()->getHost());

        return $host === $self || ($main !== '' && ($host === $main || Str::endsWith($host, '.'.$main)));
    }

    /** @return array{0: string, 1: string} */
    private static function known(string $host): array
    {
        foreach (self::REFERRERS as $suffix => $pair) {
            $match = str_ends_with($suffix, '.')
                ? (bool) preg_match('/(^|\.)'.preg_quote($suffix, '/').'[a-z.]+$/', $host)
                : ($host === $suffix || str_ends_with($host, '.'.$suffix));
            if ($match) {
                return $pair;
            }
        }

        return [Str::limit($host, 60, ''), 'referral'];
    }
}
