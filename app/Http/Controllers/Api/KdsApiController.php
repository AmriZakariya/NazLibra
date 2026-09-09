<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KdsLine;
use App\Models\Tenant;
use App\Services\Kds\KdsSyncService;
use App\Support\ApiActionContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cloud mirror of the kitchen display.
 *
 * The kitchen runs on the shop LAN and must keep working with no internet, so
 * nothing here is on the critical path of a service. It exists so the owner
 * can see, afterwards, how long dishes actually took and how often an order
 * was pulled after the kitchen had started it.
 */
class KdsApiController extends Controller
{
    public function __construct(private readonly KdsSyncService $sync)
    {
    }

    /** POST /api/v1/kds/sync — push fires and dishes recorded on a device. */
    public function store(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('api_tenant');
        /** @var ApiActionContext $action */
        $action = $request->attributes->get('api_action_context');

        $data = $request->validate([
            'fires' => ['array'],
            'fires.*' => ['array'],
            'lines' => ['array'],
            'lines.*' => ['array'],
        ]);

        $result = $this->sync->sync(
            $tenant,
            $data['fires'] ?? [],
            $data['lines'] ?? [],
            $action->location?->id,
        );

        return response()->json(['ok' => true] + $result);
    }

    /**
     * GET /api/v1/kds/report — prep times for a date range.
     *
     * Measured from the fire to the moment the dish was marked ready, on the
     * server-corrected clock the devices already agree on. Dishes that never
     * reached ready are excluded from the averages and counted separately:
     * folding them in as zero would make a bad service look fast.
     */
    public function report(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('api_tenant');

        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $from = isset($data['from'])
            ? \Illuminate\Support\Carbon::parse($data['from'])->startOfDay()
            : now()->startOfDay();
        $to = isset($data['to'])
            ? \Illuminate\Support\Carbon::parse($data['to'])->endOfDay()
            : now()->endOfDay();

        $lines = KdsLine::query()
            ->where('tenant_id', $tenant->id)
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

        return response()->json([
            'ok' => true,
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'dishes' => $dishes,
            'cancelled' => $cancelled,
            'cancelled_after_start' => $cancelledAfterStart,
        ]);
    }
}
