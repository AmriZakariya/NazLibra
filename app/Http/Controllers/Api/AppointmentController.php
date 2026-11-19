<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Tenant;
use App\Support\ApiActionContext;
use App\Support\AppModules;
use App\Support\Appointments;
use App\Support\OnlineBooking;
use App\Support\Schedules;
use App\Models\AppointmentBlock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The agenda for the till: the day's bookings, booking, moving, and the
 * status a barber changes from the chair (arrived, done, no-show).
 */
class AppointmentController extends Controller
{
    /** GET /api/v1/appointments?date=YYYY-MM-DD — one day, in the shop's day. */
    public function index(Request $request): JsonResponse
    {
        $tenant = $this->tenant($request);
        if ($off = $this->moduleOff($tenant)) {
            return $off;
        }
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $date = $data['date'] ?? now(Appointments::timezone($tenant))->toDateString();

        return response()->json(Schedules::dayPayload($tenant, $date) + [
            'ok' => true,
            'date' => $date,
            'week' => Appointments::weekCounts($tenant, $date),
            'appointments' => Appointments::forDay($tenant, $date)
                ->map(fn (Appointment $a) => Appointments::toApi($a, $tenant))
                ->values(),
        ]);
    }

    /**
     * GET /api/v1/appointments/requests — what clients asked for on the
     * public page and nobody has answered, whatever the day; and the link
     * to share (null while the page is off).
     */
    public function requests(Request $request): JsonResponse
    {
        $tenant = $this->tenant($request);
        if ($off = $this->moduleOff($tenant)) {
            return $off;
        }

        return response()->json([
            'ok' => true,
            'booking_url' => OnlineBooking::enabled($tenant) ? OnlineBooking::url() : null,
            'appointments' => OnlineBooking::pending($tenant)
                ->map(fn (Appointment $a) => Appointments::toApi($a, $tenant))
                ->values(),
        ]);
    }

    /** POST /api/v1/appointments */
    public function store(Request $request): JsonResponse
    {
        $tenant = $this->tenant($request);
        if ($off = $this->moduleOff($tenant)) {
            return $off;
        }
        $appointment = Appointments::save($tenant, $request->validate(Appointments::rules()), $this->actor($request)?->actor);

        return response()->json(['ok' => true, 'appointment' => Appointments::toApi($appointment->load('staff', 'contact'), $tenant)], 201);
    }

    /** PATCH /api/v1/appointments/{appointment} — move, reassign, rename. */
    public function update(Request $request, Appointment $appointment): JsonResponse
    {
        $tenant = $this->tenant($request);
        if ($off = $this->moduleOff($tenant)) {
            return $off;
        }
        abort_unless($appointment->tenant_id === $tenant->id, 404);
        $appointment = Appointments::save($tenant, $request->validate(Appointments::rules(partial: true)), $this->actor($request)?->actor, $appointment);

        return response()->json(['ok' => true, 'appointment' => Appointments::toApi($appointment->load('staff', 'contact'), $tenant)]);
    }

    /**
     * POST /api/v1/appointments/blocks — « Pause », « Absent », « Formation ».
     * starts_at is the shop's time, like a booking's.
     */
    public function storeBlock(Request $request): JsonResponse
    {
        $tenant = $this->tenant($request);
        if ($off = $this->moduleOff($tenant)) {
            return $off;
        }
        $data = $request->validate([
            // Not "user_id": the API refuses that name, the actor comes from the token.
            'staff_user_id' => ['required', 'integer'],
            'starts_at' => ['required', 'date'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
            'kind' => ['required', Rule::in(AppointmentBlock::KINDS)],
            'note' => ['nullable', 'string', 'max:190'],
        ]);
        $block = Schedules::addBlock($tenant, ['user_id' => (int) $data['staff_user_id']] + $data, $this->actor($request)?->actor?->id);

        return response()->json(['ok' => true, 'block' => Schedules::blockToApi($block)], 201);
    }

    /** DELETE /api/v1/appointments/blocks/{block} */
    public function destroyBlock(Request $request, AppointmentBlock $block): JsonResponse
    {
        $tenant = $this->tenant($request);
        if ($off = $this->moduleOff($tenant)) {
            return $off;
        }
        abort_unless($block->tenant_id === $tenant->id, 404);
        $block->delete();

        return response()->json(['ok' => true]);
    }

    /** PATCH /api/v1/appointments/{appointment}/status */
    public function status(Request $request, Appointment $appointment): JsonResponse
    {
        $tenant = $this->tenant($request);
        if ($off = $this->moduleOff($tenant)) {
            return $off;
        }
        abort_unless($appointment->tenant_id === $tenant->id, 404);
        $data = $request->validate([
            'status' => ['required', Rule::in(Appointment::STATUSES)],
            'reminded' => ['nullable', 'boolean'],
        ]);
        $appointment = Appointments::save($tenant, ['status' => $data['status']], $this->actor($request)?->actor, $appointment);
        if ($request->boolean('reminded')) {
            $appointment->update(['reminded_at' => now()]);
        }

        return response()->json(['ok' => true, 'appointment' => Appointments::toApi($appointment->load('staff', 'contact'), $tenant)]);
    }

    private function tenant(Request $request): Tenant
    {
        return $request->attributes->get('api_tenant');
    }

    private function actor(Request $request): ?ApiActionContext
    {
        return $request->attributes->get('api_action_context');
    }

    private function moduleOff(Tenant $tenant): ?JsonResponse
    {
        return AppModules::enabled($tenant, 'appointments') ? null : response()->json([
            'ok' => false, 'error' => 'module_disabled', 'message' => 'Le module Rendez-vous est désactivé.',
        ], 403);
    }
}
