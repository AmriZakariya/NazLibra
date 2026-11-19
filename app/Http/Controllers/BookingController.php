<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\Tenant;
use App\Support\Appointments;
use App\Support\FromPrice;
use App\Support\Locale;
use App\Support\Money;
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

        $services = OnlineBooking::services($tenant);

        return view('booking.show', [
            'tenant' => $tenant,
            'services' => $services,
            'staff' => OnlineBooking::staff($tenant),
            'serviceStaff' => \App\Support\ServiceStaff::map($tenant),
            'days' => OnlineBooking::openDays($tenant),
            'dayStrip' => $this->dayStrip($tenant),
            'cards' => $this->cards($tenant, $services),
            'categories' => $services->pluck('category')->filter()->unique('id')->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->values()->all(),
            'shop' => $this->shop($tenant),
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
            'item' => ['nullable', 'integer'],
            'variant' => ['nullable', 'integer'],
        ]);
        $service = isset($data['item']) ? OnlineBooking::services($tenant)->firstWhere('id', (int) $data['item']) : null;
        $variant = OnlineBooking::variantOf($service, isset($data['variant']) ? (int) $data['variant'] : null);

        return response()->json([
            'slots' => OnlineBooking::slots(
                $tenant, $data['date'], isset($data['staff']) ? (int) $data['staff'] : null,
                null, OnlineBooking::durationOf($tenant, $service, $variant), $service,
            ),
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
            'variant_id' => ['nullable', 'integer'],
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

    /**
     * What each service card needs, ready to print: photo, the shop's price
     * (« dès » when it is a floor or the variants differ), the length (a
     * range when variants differ) and the options of a variant service.
     *
     * @return list<array<string, mixed>>
     */
    private function cards(Tenant $tenant, \Illuminate\Support\Collection $services): array
    {
        $money = Money::for($tenant);
        $word = __('booking.from');

        return $services->map(function (Item $service) use ($tenant, $money, $word) {
            $prices = $service->variants->map(fn ($v) => (float) $v->price());
            $price = $prices->isNotEmpty() ? $prices->min() : $service->sale_price;
            $from = (bool) $service->price_is_from || $prices->unique()->count() > 1;
            $variants = $service->variants->map(fn ($v) => [
                'id' => $v->id,
                'name' => $v->name,
                'price' => $money->format($v->price()),
                'minutes' => OnlineBooking::durationOf($tenant, $service, $v),
            ])->values();
            $minutes = $variants->isNotEmpty() ? $variants->pluck('minutes') : collect([OnlineBooking::durationOf($tenant, $service)]);
            $photo = collect($service->images)->first();

            return [
                'id' => $service->id,
                'title' => $service->title,
                'category_id' => $service->category_id,
                'category' => $service->category?->name,
                'photo' => $photo ? asset('storage/'.$photo) : null,
                'price' => FromPrice::label($money, $price, $from, $word),
                'minutes_min' => (int) $minutes->min(),
                'minutes_max' => (int) $minutes->max(),
                'variants' => $variants->all(),
            ];
        })->all();
    }

    /**
     * The days the strip shows, from today: each says whether the shop takes
     * bookings that day (hours, a barber working, no holiday).
     *
     * @return list<array{date: string, open: bool}>
     */
    private function dayStrip(Tenant $tenant): array
    {
        $open = array_flip(OnlineBooking::openDays($tenant));
        $today = CarbonImmutable::now(Appointments::timezone($tenant))->startOfDay();
        $out = [];
        for ($i = 0; $i <= OnlineBooking::DAYS_AHEAD; $i++) {
            $date = $today->addDays($i)->toDateString();
            $out[] = ['date' => $date, 'open' => isset($open[$date])];
        }

        return $out;
    }

    /** The header: address, phone links and the week's opening hours. */
    private function shop(Tenant $tenant): array
    {
        $tz = Appointments::timezone($tenant);
        $now = CarbonImmutable::now($tz)->locale(app()->getLocale());
        $hours = OnlineBooking::settings($tenant)['hours'];
        $week = [];
        foreach ($hours as $iso => $range) {
            $week[] = [
                'day' => ucfirst($now->startOfWeek(CarbonImmutable::MONDAY)->addDays($iso - 1)->isoFormat('dddd')),
                'today' => $iso === $now->dayOfWeekIso,
                // Isolated left-to-right: in Arabic the range must not flip.
                'hours' => $range ? "\u{2066}".$range[0].' – '.$range[1]."\u{2069}" : null,
            ];
        }
        $e164 = $tenant->phone ? Phone::internationalize((string) $tenant->phone, Phone::defaultCountry($tenant)) : null;
        $digits = preg_replace('/\D+/', '', (string) $e164);
        $valid = strlen($digits) >= 8;

        return [
            'address' => $tenant->address ? trim(preg_replace('/\s+/', ' ', (string) $tenant->address)) : null,
            'tel' => $valid ? 'tel:+'.$digits : null,
            'whatsapp' => $valid ? 'https://wa.me/'.$digits : null,
            'week' => $week,
            'today' => $week[$now->dayOfWeekIso - 1],
        ];
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
