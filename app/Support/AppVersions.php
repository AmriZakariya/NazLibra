<?php

namespace App\Support;

use App\Models\AppUpdatePolicy;
use Illuminate\Support\Facades\Storage;

/**
 * MASTER: whether an app at a given version may go on (`ok`), is offered an
 * update (`soft`), or must update before anything else (`force`).
 *
 * A version that cannot be read is never blocked: a parsing slip must not
 * lock a shop out of its till.
 */
final class AppVersions
{
    public const OK = 'ok';

    public const SOFT = 'soft';

    public const FORCE = 'force';

    public const APK_DISK = 'local';

    public const APK_DIR = 'app-releases';

    /** "v1.2.10+12" → [1, 2, 10]; null when it is not a version. */
    public static function parse(?string $version): ?array
    {
        $version = ltrim(trim((string) $version), 'vV');
        $version = preg_replace('/[+-].*$/', '', $version);

        return preg_match('/^\d+(\.\d+){0,3}$/', (string) $version)
            ? array_map('intval', explode('.', $version))
            : null;
    }

    public static function valid(?string $version): bool
    {
        return self::parse($version) !== null;
    }

    /** <0, 0, >0 as $a is older than, equal to, newer than $b. */
    public static function compare(string $a, string $b): int
    {
        $x = self::parse($a) ?? [0];
        $y = self::parse($b) ?? [0];
        $n = max(count($x), count($y));

        return array_pad($x, $n, 0) <=> array_pad($y, $n, 0);
    }

    /** OK, SOFT or FORCE: what this version is told under this policy. */
    public static function status(?AppUpdatePolicy $policy, ?string $version): string
    {
        if (! $policy || ! self::valid($version)) {
            return self::OK;
        }
        if (self::valid($policy->min_version) && self::compare($version, $policy->min_version) < 0) {
            return self::FORCE;
        }

        return self::valid($policy->latest_version) && self::compare($version, $policy->latest_version) < 0
            ? self::SOFT
            : self::OK;
    }

    /** @return array<string, mixed> what the app is told */
    public static function decide(string $platform, ?string $version): array
    {
        $policy = in_array($platform, AppUpdatePolicy::PLATFORMS, true)
            ? AppUpdatePolicy::where('platform', $platform)->first()
            : null;

        $status = self::status($policy, $version);

        return [
            'ok' => true,
            'status' => $status,
            'current' => $version,
            'latest' => $policy?->latest_version,
            'minimum' => $policy?->min_version,
            'message' => $policy?->message ?: null,
            'store_url' => self::storeUrl($platform),
            'apk_url' => $platform === 'android' ? self::apkUrl($policy) : null,
            'apk_version' => $platform === 'android' ? $policy?->apk_version : null,
        ];
    }

    public static function storeUrl(string $platform): ?string
    {
        $url = match ($platform) {
            'android' => (string) config('castlit.brand.play_store'),
            'ios' => (string) config('castlit.brand.app_store'),
            default => '',
        };

        return $url !== '' ? $url : null;
    }

    /** The APK kept on the master, else the link set beside it. */
    public static function apkUrl(?AppUpdatePolicy $policy): ?string
    {
        if ($policy?->apk_path && Storage::disk(self::APK_DISK)->exists($policy->apk_path)) {
            // The version in the address: no cache in between serves an older file.
            return route('castlit.app.apk', ['v' => $policy->apk_version ?: $policy->updated_at?->timestamp]);
        }

        return $policy?->apk_url ?: null;
    }
}
