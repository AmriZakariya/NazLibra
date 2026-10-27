<?php

namespace App\Support;

/**
 * A device in words, for the platform admin: "Android 13 · app 1.0.5",
 * "Chrome · Windows". Built from what the app reports, or from a browser's
 * user agent; a rough read, not fingerprinting.
 */
final class DeviceLabel
{
    private const PLATFORMS = [
        'android' => 'Android', 'ios' => 'iOS', 'windows' => 'Windows',
        'macos' => 'macOS', 'linux' => 'Linux', 'web' => 'Navigateur',
    ];

    public static function describe(string $channel, ?string $platform, ?string $os, ?string $appVersion, ?string $userAgent): ?string
    {
        if ($channel === 'web') {
            return self::fromUserAgent($userAgent);
        }

        $parts = [];
        $name = self::PLATFORMS[strtolower((string) $platform)] ?? null;
        if ($os !== null && $os !== '') {
            // "Android 13" already says the platform; "13" alone does not.
            $parts[] = $name !== null && ! str_contains(strtolower($os), strtolower($name)) ? $name.' '.$os : $os;
        } elseif ($name !== null) {
            $parts[] = $name;
        }
        if ($appVersion !== null && $appVersion !== '') {
            $parts[] = 'app '.$appVersion;
        }

        return $parts === [] ? 'Application (ancienne version)' : implode(' · ', $parts);
    }

    public static function fromUserAgent(?string $ua): ?string
    {
        if ($ua === null || $ua === '') {
            return null;
        }

        $browser = match (true) {
            str_contains($ua, 'Edg/')          => 'Edge',
            str_contains($ua, 'OPR/')          => 'Opera',
            str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($ua, 'Firefox/')      => 'Firefox',
            str_contains($ua, 'CriOS'), str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Safari/')       => 'Safari',
            default                            => 'Navigateur',
        };
        $system = match (true) {
            str_contains($ua, 'iPad')          => 'iPad',
            str_contains($ua, 'iPhone')        => 'iPhone',
            str_contains($ua, 'Android')       => 'Android',
            str_contains($ua, 'Windows')       => 'Windows',
            str_contains($ua, 'Mac OS X'), str_contains($ua, 'Macintosh') => 'macOS',
            str_contains($ua, 'CrOS')          => 'ChromeOS',
            str_contains($ua, 'Linux')         => 'Linux',
            default                            => null,
        };

        return $system ? $browser.' · '.$system : $browser;
    }
}
