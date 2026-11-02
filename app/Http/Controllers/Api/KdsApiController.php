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

        $report = \App\Support\KitchenReport::compute($tenant->id, $from, $to);

        return response()->json([
            'ok' => true,
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'dishes' => $report['dishes'],
            'cancelled' => $report['cancelled'],
            'cancelled_after_start' => $report['cancelled_after_start'],
        ]);
    }
}
