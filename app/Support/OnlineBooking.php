<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\Item;
use App\Models\ItemVariant;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The public booking page: a client asks for a slot, the shop confirms.
 *
 * Settings live under settings.appointments.booking. A slot is offered when
 * it lies inside the day's opening hours, is not in the past, and the barber
 * has no booking or pending request over it — so two clients are never
 * offered the same chair. A request does not bind the shop: it arrives as
 * "requested" and only takes the chair once someone confirms it.
 */
class OnlineBooking
{
    /** ISO weekday (1 = Monday) => [open, close], or null when closed. */
    public const DEFAULT_HOURS = [
        1 => ['09:00', '19:00'], 2 => ['09:00', '19:00'], 3 => ['09:00', '19:00'],
        4 => ['09:00', '19:00'], 5 => ['09:00', '19:00'], 6 => ['09:00', '19:00'],
        7 => null,
    ];

    /** How far ahead a client may book. */
    public const DAYS_AHEAD = 30;

    /** The chair is held by these: a pending request as much as a booking. */
    public const HOLDING = ['requested', 'booked', 'arrived'];

    /** @return array{enabled: bool, duration: int, hours: array<int, ?array{0: string, 1: string}>, staff_ids: ?array<int, int>} */
    public static function settings(Tenant $tenant): array
    {
        $saved = (array) data_get($tenant->settings, 'appointments.booking', []);
        // Numeric keys survive the JSON round trip as ints in PHP arrays.
        $savedHours = isset($saved['hours']) ? (array) $saved['hours'] : self::DEFAULT_HOURS;
        $hours = [];
        foreach (array_keys(self::DEFAULT_HOURS) as $day) {
            $value = $savedHours[$day] ?? null;
            $hours[$day] = is_array($value) && count($value) === 2 && self::isTime($value[0] ?? null)
                && self::isTime($value[1] ?? null) && $value[0] < $value[1]
                ? [$value[0], $value[1]]
                : null;
        }
        $staffIds = $saved['staff_ids'] ?? null;

        return [
            'enabled' => (bool) ($saved['enabled'] ?? false),
            'duration' => max(5, min(240, (int) ($saved['duration'] ?? 30))),
            'hours' => $hours,
            'staff_ids' => is_array($staffIds) ? array_values(array_map('intval', $staffIds)) : null,
        ];
    }

    /** Open to the public: the module on and the page switched on. */
    public static function enabled(Tenant $tenant): bool
    {
        return AppModules::enabled($tenant, 'appointments') && self::settings($tenant)['enabled'];
    }

    public static function url(): string
    {
        return route('booking.show');
    }

    /** @param array<string, mixed> $input  enabled, duration, hours[d][open|close|closed], staff_ids */
    public static function save(Tenant $tenant, array $input): void
    {
        $hours = [];
        foreach (array_keys(self::DEFAULT_HOURS) as $day) {
            $row = (array) ($input['hours'][$day] ?? []);
            $open = $row['open'] ?? null;
            $close = $row['close'] ?? null;
            $hours[$day] = ! empty($row['closed']) || ! self::isTime($open) || ! self::isTime($close) || $open >= $close
                ? null
                : [$open, $close];
        }
        $team = $tenant->users()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
        $staffIds = isset($input['staff_ids']) && is_array($input['staff_ids'])
            ? array_values(array_intersect(array_map('intval', $input['staff_ids']), $team))
            : null;

        $settings = $tenant->settings ?? [];
        $settings['appointments']['booking'] = [
            'enabled' => (bool) ($input['enabled'] ?? false),
            'duration' => max(5, min(240, (int) ($input['duration'] ?? 30))),
            'hours' => $hours,
            'staff_ids' => $staffIds,
        ];
        $tenant->update(['settings' => $settings]);
    }

    /** The barbers a client can choose. */
    public static function staff(Tenant $tenant): Collection
    {
        $ids = self::settings($tenant)['staff_ids'];

        return $tenant->users()
            ->where('users.is_active', true)
            ->when($ids !== null, fn ($q) => $q->whereIn('users.id', $ids))
            ->orderBy('users.name')
            ->get(['users.id', 'users.name']);
    }

    /** The services on offer, cheapest first within their name order. */
    public static function services(Tenant $tenant): Collection
    {
        return Item::where('tenant_id', $tenant->id)
            ->where('type', 'service')
            ->whereNotIn('status', ['archived', 'inactive'])
            ->orderBy('title')
            // What the client picks among, when a service has several prices
            // or lengths: « Court / Mi-long / Long », « 30 min / 1 h ».
            ->with(['variants' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')->orderBy('id')])
            ->get(['id', 'title', 'sale_price', 'duration_minutes', 'price_is_from']);
    }

    /** The active variant [id] of [service], or null. */
    public static function variantOf(?Item $service, ?int $id): ?ItemVariant
    {
        return $service && $id ? $service->variants->firstWhere('id', $id) : null;
    }

    /** @return list<string>  the days a client may pick, Y-m-d in the shop's day */
    public static function openDays(Tenant $tenant, ?CarbonImmutable $now = null): array
    {
        $tz = Appointments::timezone($tenant);
        $today = ($now ?? CarbonImmutable::now())->setTimezone($tz)->startOfDay();
        $hours = self::settings($tenant)['hours'];
        $days = [];
        for ($i = 0; $i <= self::DAYS_AHEAD; $i++) {
            $day = $today->addDays($i);
            if ($hours[$day->isoWeekday()] !== null) {
                $days[] = $day->toDateString();
            }
        }

        return $days;
    }

    /**
     * How long [service] takes: the chosen variant's own length, else the
     * service's, else the shop's slot length.
     */
    public static function durationOf(Tenant $tenant, ?Item $service, ?ItemVariant $variant = null): int
    {
        return Appointments::minutesFor(null, $variant, $service, self::settings($tenant)['duration']);
    }

    /**
     * Free start times ("H:i", shop time) on a day, for one barber or for
     * whoever is free when [staffId] is null. Starts follow the shop's slot
     * grid; [duration] (the service's) must fit before closing and clear of
     * other bookings.
     *
     * @return list<string>
     */
    public static function slots(Tenant $tenant, string $date, ?int $staffId, ?CarbonImmutable $now = null, ?int $duration = null): array
    {
        $settings = self::settings($tenant);
        $tz = Appointments::timezone($tenant);
        $now ??= CarbonImmutable::now();
        if (! in_array($date, self::openDays($tenant, $now), true)) {
            return [];
        }
        $day = CarbonImmutable::parse($date, $tz)->startOfDay();
        [$open, $close] = $settings['hours'][$day->isoWeekday()];
        $step = $settings['duration'];
        $length = $duration ?: $step;

        $team = self::staff($tenant)->pluck('id')->map(fn ($id) => (int) $id);
        $staff = $team;
        if ($staffId !== null) {
            if (! $staff->contains($staffId)) {
                return [];
            }
            $staff = collect([$staffId]);
        }
        if ($staff->isEmpty()) {
            return [];
        }

        $busy = self::busy($tenant, $day);
        $slots = [];
        $start = CarbonImmutable::parse($date.' '.$open, $tz);
        $end = CarbonImmutable::parse($date.' '.$close, $tz);
        for ($t = $start; $t->addMinutes($length)->lessThanOrEqualTo($end); $t = $t->addMinutes($step)) {
            if ($t->lessThanOrEqualTo($now)) {
                continue;
            }
            if (self::firstFree($staff, $team, $busy, $t, $t->addMinutes($length)) !== null) {
                $slots[] = $t->format('H:i');
            }
        }

        return $slots;
    }

    /**
     * Records a client's request. The slot is checked again here: the page
     * may have been open while someone else took it.
     *
     * @param  array{item_id: int, variant_id?: ?int, staff_user_id: ?int, date: string, time: string, customer_name: string, customer_phone: string, note: ?string}  $data
     */
    public static function request(Tenant $tenant, array $data, ?CarbonImmutable $now = null): Appointment
    {
        $tz = Appointments::timezone($tenant);
        $service = self::services($tenant)->firstWhere('id', (int) $data['item_id']);
        if (! $service) {
            throw ValidationException::withMessages(['item_id' => __('booking.error_service')]);
        }
        // A service with several prices or lengths is booked as one of them.
        $variant = self::variantOf($service, isset($data['variant_id']) ? (int) $data['variant_id'] : null);
        if ($service->variants->isNotEmpty() && ! $variant) {
            throw ValidationException::withMessages(['variant_id' => __('booking.error_variant')]);
        }
        $staffId = $data['staff_user_id'] ? (int) $data['staff_user_id'] : null;
        $duration = self::durationOf($tenant, $service, $variant);
        if (! in_array($data['time'], self::slots($tenant, $data['date'], $staffId, $now, $duration), true)) {
            throw ValidationException::withMessages(['time' => __('booking.error_slot_taken')]);
        }

        $start = CarbonImmutable::parse($data['date'].' '.$data['time'], $tz);
        // "Peu importe": the first barber free then, so the shop confirms a
        // request that already fits.
        $team = self::staff($tenant)->pluck('id')->map(fn ($id) => (int) $id);
        $staffId ??= self::firstFree(
            $team,
            $team,
            self::busy($tenant, $start->startOfDay()),
            $start,
            $start->addMinutes($duration),
        );

        return Appointment::create([
            'tenant_id' => $tenant->id,
            'staff_user_id' => $staffId,
            'item_id' => $service->id,
            'variant_id' => $variant?->id,
            'service_name' => $variant ? $service->title.' — '.$variant->name : $service->title,
            'customer_name' => $data['customer_name'],
            'customer_phone' => $data['customer_phone'],
            'note' => $data['note'] ?? null,
            'starts_at' => $start->utc(),
            'duration_minutes' => $duration,
            'status' => 'requested',
        ]);
    }

    /** Requests nobody has answered yet, soonest first; past ones are dropped. */
    public static function pending(Tenant $tenant): Collection
    {
        return Appointment::query()
            ->with(['staff:id,name', 'contact:id,name,phone,notes'])
            ->where('tenant_id', $tenant->id)
            ->where('status', 'requested')
            ->where('starts_at', '>=', now()->subHours(2))
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * What holds a chair on [day]: [staff id or null, start, end] per booking
     * or pending request. [ignore] is a booking being edited or confirmed,
     * which must not clash with itself.
     *
     * @return Collection<int, array{0: int|null, 1: CarbonImmutable, 2: CarbonImmutable}>
     */
    public static function busy(Tenant $tenant, CarbonImmutable $day, ?int $ignore = null): Collection
    {
        return Appointment::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', self::HOLDING)
            ->when($ignore, fn ($q) => $q->whereKeyNot($ignore))
            // From the evening before: a late booking can run past midnight.
            ->whereBetween('starts_at', [$day->subHours(10)->utc(), $day->endOfDay()->utc()])
            ->get(['staff_user_id', 'starts_at', 'duration_minutes'])
            ->map(fn (Appointment $a) => [
                $a->staff_user_id ? (int) $a->staff_user_id : null,
                CarbonImmutable::instance($a->starts_at),
                CarbonImmutable::instance($a->endsAt()),
            ]);
    }

    /**
     * The first barber in [wanted] who can take [start, end), or null.
     *
     * A booking made without a barber (by phone, at the desk) stays
     * unassigned, but it still occupies SOMEONE: each such booking over the
     * window consumes one distinct barber of the [team] who has no booking of
     * their own over it. So a barber is free when they have nothing named on
     * them AND the others who are free can absorb every staff-less booking —
     * otherwise that booking would end up on them. « Peu importe » is free
     * only if at least one barber remains after that.
     *
     * @param  Collection<int, int>  $wanted  who the client may pick (one barber, or the whole team)
     * @param  Collection<int, int>  $team  every barber a staff-less booking could land on
     */
    public static function firstFree(Collection $wanted, Collection $team, Collection $busy, CarbonImmutable $start, CarbonImmutable $end): ?int
    {
        $over = $busy->filter(fn (array $b) => $b[1]->lessThan($end) && $b[2]->greaterThan($start));
        $named = $over->pluck(0)->filter(fn ($id) => $id !== null)->unique();
        $free = $team->reject(fn (int $id) => $named->contains($id));
        $floating = $over->filter(fn (array $b) => $b[0] === null)->count();

        // Someone must be left once every staff-less booking has its own barber.
        if ($free->count() - $floating < 1) {
            return null;
        }

        return $wanted->first(fn (int $id) => $free->contains($id));
    }

    private static function isTime(mixed $value): bool
    {
        return is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1;
    }
}
