<?php

namespace App\Support;

use App\Models\Tenant;
use Carbon\Carbon;
use Carbon\CarbonInterface;
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

    public static function format(mixed $value, Tenant|string|null $tenant = null, string $format = 'd/m/Y H:i', string $empty = '—'): string
    {
        if ($value === null || $value === '') {
            return $empty;
        }

        $date = $value instanceof CarbonInterface
            ? $value
            : Carbon::parse($value);

        return $date->copy()->setTimezone(self::timezone($tenant))->format($format);
    }

    /**
     * A date or time typed in a form, read on the shop's clock and returned in
     * UTC for storage. A value that already says its offset ("…Z", "+01:00",
     * as the app sends) is taken as it says. Empty gives null.
     */
    public static function fromInput(mixed $value, Tenant|string|null $tenant = null): ?\Illuminate\Support\Carbon
    {
        if ($value instanceof CarbonInterface) {
            return \Illuminate\Support\Carbon::instance($value)->utc();
        }
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $value = trim((string) $value);
        $explicit = (bool) preg_match('/(Z|[+-]\d{2}:?\d{2})$/i', $value) && str_contains($value, 'T');

        // Laravel's Carbon, which the models and callers type against.
        $parsed = $explicit
            ? \Illuminate\Support\Carbon::parse($value)
            : \Illuminate\Support\Carbon::parse($value, self::timezone($tenant));

        return $parsed->utc();
    }

    /** A stored time (or now) as a form field shows it, on the shop's clock. */
    public static function inputValue(mixed $value = null, Tenant|string|null $tenant = null, string $format = 'Y-m-d\TH:i'): string
    {
        $date = $value === null || $value === ''
            ? now()
            : ($value instanceof CarbonInterface ? $value : Carbon::parse($value));

        return $date->copy()->setTimezone(self::timezone($tenant))->format($format);
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
