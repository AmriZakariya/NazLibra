<?php

namespace App\Http\Controllers;

use App\Models\AppointmentBlock;
use App\Models\ScheduleException;
use App\Models\ShopClosure;
use App\Models\Tenant;
use App\Models\User;
use App\Support\AppModules;
use App\Support\Appointments;
use App\Support\Schedules;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** « Horaires »: each person's week, days off, holidays, and the agenda's blocks. */
class AgendaScheduleController extends Controller
{
    public function index(): View
    {
        $tenant = $this->tenant();
        $team = $tenant->users()->where('users.is_active', true)->orderBy('users.name')->get(['users.id', 'users.name']);
        $today = now(Appointments::timezone($tenant))->toDateString();

        return view('appointments.schedules', [
            'tenant' => $tenant,
            'team' => $team,
            'weeks' => Schedules::weeks($tenant, $team->pluck('id')),
            'exceptions' => ScheduleException::where('tenant_id', $tenant->id)->where('date', '>=', $today)
                ->orderBy('date')->get(),
            'closures' => ShopClosure::where('tenant_id', $tenant->id)->where('ends_on', '>=', $today)->orderBy('starts_on')->get(),
        ]);
    }

    /** One person's week. « Suivre la boutique » removes the personal schedule. */
    public function saveWeek(Request $request, User $user): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless($tenant->users()->whereKey($user->id)->exists(), 404);
        $request->validate([
            'week' => ['nullable', 'array'],
            'week.*.off' => ['nullable', 'boolean'],
            'week.*.*.open' => ['nullable', 'date_format:H:i'],
            'week.*.*.close' => ['nullable', 'date_format:H:i'],
        ]);
        if ($request->boolean('follow_shop')) {
            Schedules::saveWeek($tenant, $user->id, null);

            return back()->with('status', 'Horaires de '.$user->name.' : ceux de la boutique.');
        }
        $week = [];
        foreach (range(1, 7) as $weekday) {
            $row = (array) $request->input("week.$weekday", []);
            $week[$weekday] = ! empty($row['off'])
                ? []
                : array_values(array_filter($row, fn ($slot) => is_array($slot)));
        }
        Schedules::saveWeek($tenant, $user->id, $week);

        return back()->with('status', 'Horaires de '.$user->name.' enregistrés.');
    }

    public function storeException(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'kind' => ['required', Rule::in(['off', 'custom'])],
            'starts_at' => ['nullable', 'date_format:H:i', 'required_if:kind,custom'],
            'ends_at' => ['nullable', 'date_format:H:i', 'required_if:kind,custom', 'after:starts_at'],
            'note' => ['nullable', 'string', 'max:190'],
        ]);
        abort_unless($tenant->users()->whereKey($data['user_id'])->exists(), 404);
        // One answer per person and date: the newest replaces.
        ScheduleException::where('tenant_id', $tenant->id)->where('user_id', $data['user_id'])->whereDate('date', $data['date'])->delete();
        ScheduleException::create($data + ['tenant_id' => $tenant->id]);

        return back()->with('status', 'Exception enregistrée.');
    }

    public function destroyException(ScheduleException $exception): RedirectResponse
    {
        abort_unless($exception->tenant_id === $this->tenant()->id, 404);
        $exception->delete();

        return back()->with('status', 'Exception supprimée.');
    }

    public function storeClosure(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        $data = $request->validate([
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'label' => ['nullable', 'string', 'max:190'],
        ]);
        ShopClosure::create($data + ['tenant_id' => $tenant->id]);

        return back()->with('status', 'Fermeture enregistrée.');
    }

    public function destroyClosure(ShopClosure $closure): RedirectResponse
    {
        abort_unless($closure->tenant_id === $this->tenant()->id, 404);
        $closure->delete();

        return back()->with('status', 'Fermeture supprimée.');
    }

    /** « Pause », « Absent », « Formation » on the agenda. */
    public function storeBlock(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless(AppModules::enabled($tenant, 'appointments'), 404);
        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
            'kind' => ['required', Rule::in(AppointmentBlock::KINDS)],
            'note' => ['nullable', 'string', 'max:190'],
        ]);
        Schedules::addBlock($tenant, [
            'user_id' => (int) $data['user_id'], 'starts_at' => $data['date'].' '.$data['time'],
            'duration_minutes' => (int) $data['duration_minutes'], 'kind' => $data['kind'], 'note' => $data['note'] ?? null,
        ], $request->user()?->id);

        return redirect()->route('appointments', ['date' => $data['date']])->with('status', 'Créneau bloqué.');
    }

    public function destroyBlock(AppointmentBlock $block): RedirectResponse
    {
        abort_unless($block->tenant_id === $this->tenant()->id, 404);
        $date = $block->starts_at->setTimezone(Appointments::timezone($this->tenant()))->toDateString();
        $block->delete();

        return redirect()->route('appointments', ['date' => $date])->with('status', 'Créneau libéré.');
    }

    private function tenant(): Tenant
    {
        $tenant = TenantContext::require(request(), auth()->user());
        abort_unless(AppModules::enabled($tenant, 'appointments'), 404);

        return $tenant;
    }
}
