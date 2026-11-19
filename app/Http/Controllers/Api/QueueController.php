<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\QueueEntry;
use App\Models\Tenant;
use App\Support\ApiActionContext;
use App\Support\AppModules;
use App\Support\Appointments;
use App\Support\WalkInQueue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The walk-in queue for the till: the board, add, call the next, seat, gone. */
class QueueController extends Controller
{
    /** GET /api/v1/queue */
    public function index(Request $request): JsonResponse
    {
        $tenant = $this->tenant($request);
        if ($off = $this->moduleOff($tenant)) {
            return $off;
        }

        return response()->json([
            'ok' => true,
            'entries' => WalkInQueue::board($tenant)->map(fn (array $row) => $this->payload($row['entry'], $tenant, $row['wait_minutes']))->values(),
        ]);
    }

    /** POST /api/v1/queue */
    public function store(Request $request): JsonResponse
    {
        $tenant = $this->tenant($request);
        if ($off = $this->moduleOff($tenant)) {
            return $off;
        }
        $entry = WalkInQueue::add($tenant, $request->validate([
            'customer_name' => ['nullable', 'string', 'max:160'],
            'customer_phone' => ['nullable', 'string', 'max:40'],
            'item_id' => ['nullable', 'integer'],
            'variant_id' => ['nullable', 'integer'],
            'staff_user_id' => ['nullable', 'integer'],
        ]));

        return response()->json(['ok' => true, 'entry' => $this->payload($entry, $tenant, null)], 201);
    }

    /** POST /api/v1/queue/next {staff_user_id} — « Appeler le suivant ». */
    public function call(Request $request): JsonResponse
    {
        $tenant = $this->tenant($request);
        if ($off = $this->moduleOff($tenant)) {
            return $off;
        }
        $data = $request->validate(['staff_user_id' => ['required', 'integer']]);
        $entry = WalkInQueue::callNext($tenant, (int) $data['staff_user_id']);

        return response()->json(['ok' => true, 'entry' => $entry ? $this->payload($entry, $tenant, null) : null]);
    }

    /** POST /api/v1/queue/{entry}/seat — « Installé »: returns the agenda booking to cash out. */
    public function seat(Request $request, QueueEntry $entry): JsonResponse
    {
        $tenant = $this->tenant($request);
        if ($off = $this->moduleOff($tenant)) {
            return $off;
        }
        $entry = WalkInQueue::seat($tenant, $entry, $request->attributes->get('api_action_context')?->actor);

        return response()->json([
            'ok' => true,
            'entry' => $this->payload($entry, $tenant, null),
            'appointment' => Appointments::toApi($entry->appointment->load('staff', 'contact'), $tenant),
        ]);
    }

    /** POST /api/v1/queue/{entry}/leave — « Parti ». */
    public function leave(Request $request, QueueEntry $entry): JsonResponse
    {
        $tenant = $this->tenant($request);
        if ($off = $this->moduleOff($tenant)) {
            return $off;
        }

        return response()->json(['ok' => true, 'entry' => $this->payload(WalkInQueue::leave($tenant, $entry), $tenant, null)]);
    }

    /** @return array<string, mixed> */
    private function payload(QueueEntry $e, Tenant $tenant, ?int $wait): array
    {
        return [
            'id' => $e->id,
            'ticket' => $e->ticket_number,
            'status' => $e->status,
            'customer_name' => $e->customer_name,
            'customer_phone' => $e->customer_phone,
            'contact_id' => $e->contact_id,
            'item_id' => $e->item_id,
            'variant_id' => $e->variant_id,
            'service_name' => $e->service_name,
            'preferred_staff_user_id' => $e->preferred_staff_user_id,
            'staff_user_id' => $e->staff_user_id,
            'staff_name' => $e->staff?->name,
            'duration_minutes' => $e->duration_minutes,
            'wait_minutes' => $wait,
            'arrived_at' => $e->arrived_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'called_at' => $e->called_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'appointment_id' => $e->appointment_id,
        ];
    }

    private function tenant(Request $request): Tenant
    {
        return $request->attributes->get('api_tenant');
    }

    private function moduleOff(Tenant $tenant): ?JsonResponse
    {
        return AppModules::enabled($tenant, 'queue') && AppModules::enabled($tenant, 'appointments') ? null : response()->json([
            'ok' => false, 'error' => 'module_disabled', 'message' => 'Le module File d’attente est désactivé.',
        ], 403);
    }
}
