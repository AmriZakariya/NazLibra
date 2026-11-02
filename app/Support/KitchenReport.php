<?php

namespace App\Support;

use App\Models\KdsLine;
use Illuminate\Support\Carbon;

/**
 * How long the kitchen takes, per dish: the app's report and the web's.
 *
 * Measured from the fire to the moment the dish was marked ready, on the
 * server-corrected clock the devices already agree on. Dishes that never
 * reached ready are excluded from the averages and counted separately:
 * folding them in as zero would make a bad service look fast.
 */
class KitchenReport
{
    /** @return array{dishes: list<array{dish: string, count: int, timed: int, average_seconds: ?int}>, cancelled: int, cancelled_after_start: int} */
    public static function compute(int $tenantId, Carbon $from, Carbon $to): array
    {
        $lines = KdsLine::query()
            ->where('tenant_id', $tenantId)
            ->whereBetween('fired_at', [$from, $to])
            ->get(['name', 'item_id', 'quantity', 'status', 'fired_at',
                'status_at', 'cancel_was_started']);

        $byDish = [];
        $cancelled = 0;
        $cancelledAfterStart = 0;

        foreach ($lines as $line) {
            if ($line->status === 'cancelled') {
                $cancelled++;
                if ($line->cancel_was_started) {
                    $cancelledAfterStart++;
                }

                continue;
            }

            $key = $line->name;
            $byDish[$key] ??= ['dish' => $key, 'count' => 0, 'seconds' => 0, 'timed' => 0];
            $byDish[$key]['count']++;

            // Only a dish that actually reached the kitchen's hands has a
            // duration. status_at is null until someone touched it.
            if ($line->status_at === null || $line->fired_at === null) {
                continue;
            }
            if (! in_array($line->status, ['ready', 'served'], true)) {
                continue;
            }

            $seconds = $line->fired_at->diffInSeconds($line->status_at, false);
            // A negative duration means the device clocks disagreed; counting
            // it would drag the average below what any kitchen can do.
            if ($seconds < 0) {
                continue;
            }

            $byDish[$key]['seconds'] += $seconds;
            $byDish[$key]['timed']++;
        }

        $dishes = array_values(array_map(function (array $row): array {
            $row['average_seconds'] = $row['timed'] > 0
                ? (int) round($row['seconds'] / $row['timed'])
                : null;
            unset($row['seconds']);

            return $row;
        }, $byDish));

        usort($dishes, fn ($a, $b) => $b['count'] <=> $a['count']);

        return ['dishes' => $dishes, 'cancelled' => $cancelled, 'cancelled_after_start' => $cancelledAfterStart];
    }

    /** "12 min 30 s", "45 s", or "—" when nothing was timed. */
    public static function duration(?int $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }
        $minutes = intdiv($seconds, 60);
        $rest = $seconds % 60;

        return $minutes > 0 ? $minutes.' min'.($rest ? ' '.$rest.' s' : '') : $rest.' s';
    }
}
