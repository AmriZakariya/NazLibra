<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Support\Appointments;
use App\Support\Locale;
use App\Support\OnlineBooking;
use App\Support\Phone;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The shop's public booking page: pick a service, a barber, a free slot,
 * leave a name and a phone. The request waits in the agenda for the shop.
 */
class BookingController extends Controller
{
    /** Requests one address may send in an hour. */
    private const PER_HOUR = 5;

    public function show(Request $request): View
    {
        $tenant = $this->open($request);

        return view('booking.show', [
            'tenant' => $tenant,
            'services' => OnlineBooking::services($tenant),
            'staff' => OnlineBooking::staff($tenant),
            'days' => OnlineBooking::openDays($tenant),
            'duration' => OnlineBooking::settings($tenant)['duration'],
            'sent' => session('booking_sent'),
        ]);
    }

    /** GET ?date=Y-m-d&staff=id — the free times, for the page's script. */
    public function slots(Request $request): JsonResponse
    {
        $tenant = $this->open($request);
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'staff' => ['nullable', 'integer'],
        ]);

        return response()->json([
            'slots' => OnlineBooking::slots($tenant, $data['date'], isset($data['staff']) ? (int) $data['staff'] : null),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = $this->open($request);

        // A filled hidden field is a robot: answered as if it worked, so it
        // learns nothing, and nothing reaches the agenda.
        if (filled($request->input('website'))) {
            return redirect()->route('booking.show')->with('booking_sent', ['honeypot' => true]);
        }

        $key = 'booking:'.$tenant->id.':'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, self::PER_HOUR)) {
            throw ValidationException::withMessages(['customer_name' => __('booking.error_too_many')]);
        }

        $data = $request->validate([
            'item_id' => ['required', 'integer'],
            'staff_user_id' => ['nullable', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'customer_name' => ['required', 'string', 'max:160'],
            'customer_phone' => ['required', 'string', 'max:40', 'regex:/^\\+?[0-9\\s().-]{8,24}$/'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $data['customer_phone'] = Phone::internationalize($data['customer_phone'], Phone::defaultCountry($tenant));
        $data['staff_user_id'] ??= null;

        $appointment = OnlineBooking::request($tenant, $data);
        RateLimiter::hit($key, 3600);

        $when = CarbonImmutable::instance($appointment->starts_at)->setTimezone(Appointments::timezone($tenant));

        return redirect()->route('booking.show')->with('booking_sent', [
            'service' => $appointment->service_name,
            'date' => $when->locale(app()->getLocale())->isoFormat('dddd D MMMM'),
            'time' => $when->format('H:i'),
            'staff' => $appointment->staff?->name,
        ]);
    }

    /** The shop, in its language, or the closed page when booking is off. */
    private function open(Request $request): Tenant
    {
        $tenant = TenantContext::require($request, null, false);
        Locale::apply($tenant);
        if (! OnlineBooking::enabled($tenant)) {
            throw new HttpResponseException(
                response()->view('booking.closed', ['tenant' => $tenant], 503)
            );
        }

        return $tenant;
    }
}
