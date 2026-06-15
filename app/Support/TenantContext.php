<?php

namespace App\Support;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TenantContext
{
    public static function resolve(?Request $request = null, ?User $user = null, bool $preferUser = true): ?Tenant
    {
        if ($preferUser) {
            $user ??= $request?->user();
        }

        if ($preferUser && $user?->currentTenant) {
            return $user->currentTenant;
        }

        $configuredSlug = trim((string) config('app.tenant_slug', ''));
        if ($configuredSlug !== '') {
            $tenant = Tenant::query()->where('slug', $configuredSlug)->first();
            if ($tenant) {
                return $tenant;
            }
        }

        $hostSlug = self::slugFromHost($request);
        if ($hostSlug !== null) {
            $tenant = Tenant::query()->where('slug', $hostSlug)->first();
            if ($tenant) {
                return $tenant;
            }
        }

        return Tenant::query()->orderBy('id')->first();
    }

    public static function require(?Request $request = null, ?User $user = null, bool $preferUser = true): Tenant
    {
        $tenant = self::resolve($request, $user, $preferUser);

        abort_unless($tenant, 503, 'Aucun tenant configuré pour cette installation.');

        return $tenant;
    }

    private static function slugFromHost(?Request $request): ?string
    {
        if (! $request) {
            return null;
        }

        $host = Str::of($request->getHost())->lower()->before(':')->value();
        if ($host === '' || in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return null;
        }

        $baseDomain = trim((string) config('app.base_domain', ''));
        if ($baseDomain !== '' && Str::endsWith($host, '.'.$baseDomain)) {
            $candidate = Str::before($host, '.'.$baseDomain);
        } else {
            $candidate = Str::before($host, '.');
        }

        if ($candidate === '' || in_array($candidate, ['www', 'app'], true)) {
            return null;
        }

        return Str::slug($candidate);
    }
}
