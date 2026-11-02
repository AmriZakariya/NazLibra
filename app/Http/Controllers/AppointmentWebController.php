<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Contact;
use App\Models\Item;
use App\Models\Tenant;
use App\Models\User;
use App\Support\AppModules;
use App\Support\Appointments;
use App\Support\OnlineBooking;
use App\Support\TenantContext;
use App\Support\TipPayouts;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** The agenda in the back office, and handing a barber their tips. */
class AppointmentWebController extends Controller
{
    public function index(Request $request): View
    {
        $tenant = $this->tenant();
        abort_unless(AppModules::enabled($tenant, 'appointments'), 404);

        $tz = Appointments::timezone($tenant);
        $date = $request->query('date');
        $day = (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date))
            ? CarbonImmutable::parse($date, $tz)
            : CarbonImmutable::now($tz);

        $appointments = Appointments::forDay($tenant, $day->toDateString());
        $staff = $tenant->users()->where('users.is_active', true)->orderBy('users.name')
            ->get(['users.id', 'users.name', 'users.avatar_color']);

        return view('appointments.index', [
            'tenant' => $tenant,
            'active' => 'appointments',
            'day' => $day,
            'tz' => $tz,
            'appointments' => $appointments,
            'staff' => $staff,
            'services' => Item::where('tenant_id', $tenant->id)->where('type', 'service')
                ->whereNotIn('status', ['archived', 'inactive'])->orderBy('title')->get(['id', 'title', 'sale_price']),
            'customers' => Contact::where('tenant_id', $tenant->id)->where('kind', 'client')
                ->orderBy('name')->limit(500)->get(['id', 'name', 'phone']),
            'whatsapp' => fn (Appointment $a) => Appointments::whatsappUrl($a, $tenant),
            'confirmation' => fn (Appointment $a) => Appointments::whatsappUrl($a, $tenant, confirmation: true),
            'requests' => OnlineBooking::pending($tenant),
            'booking' => OnlineBooking::settings($tenant),
            'team' => $tenant->users()->where('users.is_active', true)->orderBy('users.name')->get(['users.id', 'users.name']),
        ]);
    }

    /** The public page's switch, opening hours, slot length and barbers. */
    public function saveBooking(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless(AppModules::enabled($tenant, 'appointments'), 404);
        $data = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'duration' => ['required', 'integer', 'min:5', 'max:240'],
            'hours' => ['nullable', 'array'],
            'hours.*.open' => ['nullable', 'date_format:H:i'],
            'hours.*.close' => ['nullable', 'date_format:H:i'],
            'hours.*.closed' => ['nullable', 'boolean'],
            'staff_ids' => ['nullable', 'array'],
            'staff_ids.*' => ['integer'],
        ]);
        $data['enabled'] = $request->boolean('enabled');
        // No box ticked is "nobody", not "everybody": only an untouched form
        // (no field at all) keeps the whole team.
        $data['staff_ids'] = $request->has('staff_ids_sent') ? ($data['staff_ids'] ?? []) : null;
        OnlineBooking::save($tenant, $data);

        return back()->with('status', 'Réservation en ligne enregistrée.');
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless(AppModules::enabled($tenant, 'appointments'), 404);
        $data = $request->validate(Appointments::rules() + ['time' => ['nullable', 'date_format:H:i']]);
        $data = $this->withTime($data);
        $appointment = Appointments::save($tenant, $data, $request->user());

        return redirect()
            ->route('appointments', ['date' => $appointment->starts_at->setTimezone(Appointments::timezone($tenant))->toDateString()])
            ->with('status', 'Rendez-vous enregistré.');
    }

    public function update(Request $request, Appointment $appointment): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless($appointment->tenant_id === $tenant->id, 404);
        $data = $this->withTime($request->validate(Appointments::rules(partial: true) + ['time' => ['nullable', 'date_format:H:i']]));
        $appointment = Appointments::save($tenant, $data, $request->user(), $appointment);

        return redirect()
            ->route('appointments', ['date' => $appointment->starts_at->setTimezone(Appointments::timezone($tenant))->toDateString()])
            ->with('status', 'Rendez-vous mis à jour.');
    }

    public function status(Request $request, Appointment $appointment): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless($appointment->tenant_id === $tenant->id, 404);
        $data = $request->validate(['status' => ['required', Rule::in(Appointment::STATUSES)]]);
        $wasRequested = $appointment->status === 'requested';
        $appointment = Appointments::save($tenant, ['status' => $data['status']], $request->user(), $appointment);

        // A confirmed online request: the client is waiting to hear back.
        if ($wasRequested && $data['status'] === 'booked') {
            return back()
                ->with('status', 'Demande confirmée.')
                ->with('confirmation_url', Appointments::whatsappUrl($appointment, $tenant, confirmation: true));
        }

        return back()->with('status', $wasRequested && $data['status'] === 'cancelled' ? 'Demande refusée.' : 'Statut mis à jour.');
    }

    public function payTips(Request $request, User $user): RedirectResponse
    {
        $tenant = $this->tenant();
        abort_unless($tenant->users()->whereKey($user->id)->exists(), 404);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['nullable', Rule::in(['cash', 'card', 'transfer'])],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        TipPayouts::pay($tenant, $user, (float) $data['amount'], $request->user(), $data['method'] ?? 'cash', $data['note'] ?? null);

        return back()->with('status', 'Pourboires versés à '.$user->name.'.');
    }

    /** The form sends a date and a time apart; the rules want one moment. */
    private function withTime(array $data): array
    {
        if (! empty($data['time']) && ! empty($data['starts_at'])) {
            $data['starts_at'] = substr((string) $data['starts_at'], 0, 10).' '.$data['time'];
        }
        unset($data['time']);

        return $data;
    }

    private function tenant(): Tenant
    {
        return TenantContext::require(request(), auth()->user());
    }
}
