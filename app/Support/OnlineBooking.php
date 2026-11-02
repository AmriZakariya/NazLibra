<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\Item;
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
    private const HOLDING = ['requested', 'booked', 'arrived'];

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
            ->get(['id', 'title', 'sale_price', 'duration_minutes']);
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

    /** How long [service] takes: its own duration, else the shop's slot length. */
    public static function durationOf(Tenant $tenant, ?Item $service): int
    {
        return $service?->duration_minutes ?: self::settings($tenant)['duration'];
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

        $staff = self::staff($tenant)->pluck('id')->map(fn ($id) => (int) $id);
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
            if (self::firstFree($staff, $busy, $t, $t->addMinutes($length)) !== null) {
                $slots[] = $t->format('H:i');
            }
        }

        return $slots;
    }

    /**
     * Records a client's request. The slot is checked again here: the page
     * may have been open while someone else took it.
     *
     * @param  array{item_id: int, staff_user_id: ?int, date: string, time: string, customer_name: string, customer_phone: string, note: ?string}  $data
     */
    public static function request(Tenant $tenant, array $data, ?CarbonImmutable $now = null): Appointment
    {
        $tz = Appointments::timezone($tenant);
        $service = self::services($tenant)->firstWhere('id', (int) $data['item_id']);
        if (! $service) {
            throw ValidationException::withMessages(['item_id' => __('booking.error_service')]);
        }
        $staffId = $data['staff_user_id'] ? (int) $data['staff_user_id'] : null;
        $duration = self::durationOf($tenant, $service);
        if (! in_array($data['time'], self::slots($tenant, $data['date'], $staffId, $now, $duration), true)) {
            throw ValidationException::withMessages(['time' => __('booking.error_slot_taken')]);
        }

        $start = CarbonImmutable::parse($data['date'].' '.$data['time'], $tz);
        // "Peu importe": the first barber free then, so the shop confirms a
        // request that already fits.
        $staffId ??= self::firstFree(
            self::staff($tenant)->pluck('id')->map(fn ($id) => (int) $id),
            self::busy($tenant, $start->startOfDay()),
            $start,
            $start->addMinutes($duration),
        );

        return Appointment::create([
            'tenant_id' => $tenant->id,
            'staff_user_id' => $staffId,
            'item_id' => $service->id,
            'service_name' => $service->title,
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

    /** @return Collection<int, array{0: int|null, 1: CarbonImmutable, 2: CarbonImmutable}> */
    private static function busy(Tenant $tenant, CarbonImmutable $day): Collection
    {
        return Appointment::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', self::HOLDING)
            // From the evening before: a late booking can run past midnight.
            ->whereBetween('starts_at', [$day->subHours(10)->utc(), $day->endOfDay()->utc()])
            ->get(['staff_user_id', 'starts_at', 'duration_minutes'])
            ->map(fn (Appointment $a) => [
                $a->staff_user_id ? (int) $a->staff_user_id : null,
                CarbonImmutable::instance($a->starts_at),
                CarbonImmutable::instance($a->endsAt()),
            ]);
    }

    /** The first barber in [staff] with nothing over [start, end), or null. */
    private static function firstFree(Collection $staff, Collection $busy, CarbonImmutable $start, CarbonImmutable $end): ?int
    {
        return $staff->first(fn (int $id) => ! $busy->contains(
            fn (array $b) => $b[0] === $id && $b[1]->lessThan($end) && $b[2]->greaterThan($start)
        ));
    }

    private static function isTime(mixed $value): bool
    {
        return is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1;
    }
}
