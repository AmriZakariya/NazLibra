<?php

namespace App\Support;

use App\Models\Tenant;
use DateTimeZone;

class TenantClock
{
    public const DEFAULT_TIMEZONE = 'Africa/Casablanca';

    public static function timezone(Tenant|string|null $tenant = null): string
    {
        $timezone = is_string($tenant)
            ? $tenant
            : (string) ($tenant?->timezone ?: data_get($tenant?->settings, 'company_profile.timezone', self::DEFAULT_TIMEZONE));

        return self::isValid($timezone) ? $timezone : self::DEFAULT_TIMEZONE;
    }

    public static function apply(Tenant|string|null $tenant = null): string
    {
        $timezone = self::timezone($tenant);

        // Persistence, queue workers, sessions and SQL bindings must never
        // depend on which tenant happened to make the current request.
        // Tenant timezone conversion belongs at presentation boundaries.
        config(['app.timezone' => 'UTC']);
        date_default_timezone_set('UTC');

        return $timezone;
    }

    public static function offset(Tenant|string|null $tenant = null): string
    {
        $timezone = self::timezone($tenant);
        $seconds = (new DateTimeZone($timezone))->getOffset(now($timezone));
        $sign = $seconds >= 0 ? '+' : '-';
        $seconds = abs($seconds);
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return 'UTC'.$sign.str_pad((string) $hours, 2, '0', STR_PAD_LEFT).':'.str_pad((string) $minutes, 2, '0', STR_PAD_LEFT);
    }

    public static function label(Tenant|string|null $tenant = null): string
    {
        return self::timezone($tenant).' · '.self::offset($tenant);
    }

    public static function currentTimeLabel(Tenant|string|null $tenant = null): string
    {
        return now(self::timezone($tenant))->format('d/m/Y H:i');
    }

    public static function isValid(?string $timezone): bool
    {
        return is_string($timezone)
            && $timezone !== ''
            && in_array($timezone, DateTimeZone::listIdentifiers(), true);
    }

    public static function options(): array
    {
        $preferred = [
            'Africa/Casablanca',
            'Africa/El_Aaiun',
            'Europe/Paris',
            'Europe/Madrid',
            'Europe/London',
            'UTC',
        ];

        return collect($preferred)
            ->merge(DateTimeZone::listIdentifiers())
            ->unique()
            ->values()
            ->all();
    }
}
