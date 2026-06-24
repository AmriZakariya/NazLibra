<?php

namespace App\Support;

use Carbon\Carbon;
use InvalidArgumentException;

final class UtcDateTime
{
    public static function parse(string $value): Carbon
    {
        if (! preg_match('/\A(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?(Z|[+-]\d{2}:\d{2})\z/', $value, $parts)) {
            throw new InvalidArgumentException('Datetime must be RFC3339 with an explicit offset.');
        }

        $fraction = str_pad($parts[3] ?? '', 6, '0');
        $offset = $parts[4] === 'Z' ? '+00:00' : $parts[4];
        $normalized = $parts[1].'T'.$parts[2].'.'.$fraction.$offset;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.uP', $normalized);
        $errors = \DateTimeImmutable::getLastErrors();

        if (! $date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new InvalidArgumentException('Invalid RFC3339 datetime.');
        }

        return Carbon::instance($date)->utc();
    }

    public static function format(Carbon $value): string
    {
        return $value->copy()->utc()->format('Y-m-d\TH:i:s.u\Z');
    }
}
