<?php

namespace App\Support;

use App\Models\AppointmentBlock;
use App\Models\ScheduleException;
use App\Models\ShopClosure;
use App\Models\StaffSchedule;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Who works when, for the salon agenda.
 *
 * Precedence for one person on one date (the first that applies wins):
 *   1. the shop is closed that date (holiday)       -> no hours;
 *   2. an exception for that person and date         -> off, or its own hours;
 *   3. the person's weekly schedule (any row at all)  -> that weekday's intervals;
 *   4. the shop's opening hours (the default).
 *
 * Intervals are ["09:00", "13:00"] pairs in the shop's day. The same answer
 * serves the public page, the agenda and the app.
 */
final class Schedules
{
    public const BLOCK_LABELS = ['pause' => 'Pause', 'absent' => 'Absent', 'training' => 'Formation'];

    /**
     * Hours per person for each of [dates] (Y-m-d), read in four queries
     * however many days: the public page asks for a month at a time.
     *
     * @param  list<string>  $dates
     * @param  Collection<int, int>  $userIds
     * @return array<string, array<int, list<array{0: string, 1: string}>>>  date => user id => intervals
     */
    public static function plans(Tenant $tenant, array $dates, Collection $userIds): array
    {
        if ($dates === [] || $userIds->isEmpty()) {
            return [];
        }
        sort($dates);
        $first = $dates[0];
        $last = $dates[count($dates) - 1];

        $closures = ShopClosure::where('tenant_id', $tenant->id)
            ->where('starts_on', '<=', $last)->where('ends_on', '>=', $first)->get();
        $exceptions = ScheduleException::where('tenant_id', $tenant->id)
            ->whereIn('user_id', $userIds->all())
            ->whereBetween('date', [$first, $last])->orderBy('starts_at')->get()
            ->groupBy(fn (ScheduleException $e) => $e->date->toDateString());
        $weekly = StaffSchedule::where('tenant_id', $tenant->id)->whereIn('user_id', $userIds->all())
            ->orderBy('starts_at')->get()->groupBy('user_id');
        $shop = OnlineBooking::settings($tenant)['hours'];

        $plans = [];
        foreach ($dates as $date) {
            $weekday = CarbonImmutable::parse($date)->isoWeekday();
            $closed = $closures->contains(fn (ShopClosure $c) => $c->starts_on->toDateString() <= $date && $c->ends_on->toDateString() >= $date);
            foreach ($userIds as $id) {
                $id = (int) $id;
                if ($closed) {
                    $plans[$date][$id] = [];

                    continue;
                }
                $mine = $exceptions->get($date)?->where('user_id', $id);
                if ($mine && $mine->isNotEmpty()) {
                    $plans[$date][$id] = $mine->contains('kind', 'off')
                        ? []
                        : $mine->filter(fn ($e) => $e->starts_at && $e->ends_at)->map(fn ($e) => [$e->starts_at, $e->ends_at])->values()->all();

                    continue;
                }
                $rows = $weekly->get($id);
                if ($rows && $rows->isNotEmpty()) {
                    $plans[$date][$id] = $rows->where('weekday', $weekday)
                        ->filter(fn ($r) => $r->starts_at && $r->ends_at)->map(fn ($r) => [$r->starts_at, $r->ends_at])->values()->all();

                    continue;
                }
                $plans[$date][$id] = isset($shop[$weekday]) ? [[$shop[$weekday][0], $shop[$weekday][1]]] : [];
            }
        }

        return $plans;
    }

    /** @return list<array{0: string, 1: string}> */
    public static function intervals(Tenant $tenant, int $userId, string $date): array
    {
        return self::plans($tenant, [$date], collect([$userId]))[$date][$userId] ?? [];
    }

    /** Whether [start, end) lies inside ONE of [intervals] (times in the shop's day). */
    public static function covers(array $intervals, CarbonInterface $start, CarbonInterface $end): bool
    {
        $day = $start->format('Y-m-d');
        foreach ($intervals as [$open, $close]) {
            $from = CarbonImmutable::parse($day.' '.$open, $start->getTimezone());
            $to = CarbonImmutable::parse($day.' '.$close, $start->getTimezone());
            if ($from->lessThanOrEqualTo($start) && $to->greaterThanOrEqualTo($end)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the shop has said anything about hours that applies to this
     * person on this date. A shop that never did is not bothered about them:
     * it books by hand, outside the default hours, as it always could.
     */
    public static function enforced(Tenant $tenant, int $userId, string $date): bool
    {
        if (data_get($tenant->settings, 'appointments.booking.hours') !== null) {
            return true;
        }
        if (StaffSchedule::where('tenant_id', $tenant->id)->where('user_id', $userId)->exists()) {
            return true;
        }
        if (ScheduleException::where('tenant_id', $tenant->id)->where('user_id', $userId)->whereDate('date', $date)->exists()) {
            return true;
        }

        return ShopClosure::where('tenant_id', $tenant->id)->where('starts_on', '<=', $date)->where('ends_on', '>=', $date)->exists();
    }

    /** A date the whole shop is shut, or null. */
    public static function closure(Tenant $tenant, string $date): ?ShopClosure
    {
        return ShopClosure::where('tenant_id', $tenant->id)->where('starts_on', '<=', $date)->where('ends_on', '>=', $date)->first();
    }

    /** The agenda blocks of the shop's day [date]. */
    public static function blocksOn(Tenant $tenant, string $date): Collection
    {
        $tz = Appointments::timezone($tenant);
        $day = CarbonImmutable::parse($date, $tz)->startOfDay();

        return AppointmentBlock::where('tenant_id', $tenant->id)
            // From the evening before: a block can run past midnight.
            ->whereBetween('starts_at', [$day->subHours(10)->utc(), $day->endOfDay()->utc()])
            ->orderBy('starts_at')->get()
            ->filter(fn (AppointmentBlock $b) => $b->endsAt()->greaterThan($day->utc()));
    }

    /**
     * Saves a person's weekly hours. [$week] is weekday (1-7) => list of
     * ['open' => '09:00', 'close' => '13:00'] intervals; an empty list is a
     * day off. Passing null removes the personal schedule: back to the shop's hours.
     *
     * @param  array<int, list<array{open: ?string, close: ?string}>>|null  $week
     */
    public static function saveWeek(Tenant $tenant, int $userId, ?array $week): void
    {
        StaffSchedule::where('tenant_id', $tenant->id)->where('user_id', $userId)->delete();
        if ($week === null) {
            return;
        }
        for ($weekday = 1; $weekday <= 7; $weekday++) {
            $kept = [];
            foreach ($week[$weekday] ?? [] as $slot) {
                $open = $slot['open'] ?? null;
                $close = $slot['close'] ?? null;
                if (self::isTime($open) && self::isTime($close) && $open < $close) {
                    $kept[] = [$open, $close];
                }
            }
            if ($kept === []) {
                StaffSchedule::create(['tenant_id' => $tenant->id, 'user_id' => $userId, 'weekday' => $weekday]);

                continue;
            }
            foreach ($kept as [$open, $close]) {
                StaffSchedule::create(['tenant_id' => $tenant->id, 'user_id' => $userId, 'weekday' => $weekday, 'starts_at' => $open, 'ends_at' => $close]);
            }
        }
    }

    /**
     * The weekly hours of each person as the forms and the app show them:
     * user id => ['custom' => bool, 'week' => weekday => intervals].
     *
     * @return array<int, array{custom: bool, week: array<int, list<array{0: string, 1: string}>>}>
     */
    public static function weeks(Tenant $tenant, Collection $userIds): array
    {
        $shop = OnlineBooking::settings($tenant)['hours'];
        $rows = StaffSchedule::where('tenant_id', $tenant->id)->whereIn('user_id', $userIds->all())
            ->orderBy('starts_at')->get()->groupBy('user_id');
        $out = [];
        foreach ($userIds as $id) {
            $id = (int) $id;
            $week = [];
            for ($d = 1; $d <= 7; $d++) {
                $mine = $rows->get($id);
                $week[$d] = $mine && $mine->isNotEmpty()
                    ? $mine->where('weekday', $d)->filter(fn ($r) => $r->starts_at && $r->ends_at)->map(fn ($r) => [$r->starts_at, $r->ends_at])->values()->all()
                    : (isset($shop[$d]) ? [[$shop[$d][0], $shop[$d][1]]] : []);
            }
            $out[$id] = ['custom' => $rows->has($id), 'week' => $week];
        }

        return $out;
    }

    /**
     * Puts a block on someone's agenda. Refused over a booking (move it
     * first) or another block, so the agenda never says two things at once.
     *
     * @param  array{user_id: int, starts_at: string, duration_minutes: int, kind: string, note?: ?string}  $data  starts_at in the shop's time
     */
    public static function addBlock(Tenant $tenant, array $data, ?int $byUserId): AppointmentBlock
    {
        if (! $tenant->users()->whereKey($data['user_id'])->exists()) {
            throw ValidationException::withMessages(['user_id' => 'Cette personne ne fait pas partie de l’équipe.']);
        }
        $start = CarbonImmutable::parse($data['starts_at'], Appointments::timezone($tenant))->utc();
        $end = $start->addMinutes((int) $data['duration_minutes']);

        $booking = \App\Models\Appointment::query()
            ->where('tenant_id', $tenant->id)->where('staff_user_id', $data['user_id'])
            ->whereIn('status', OnlineBooking::HOLDING)
            ->where('starts_at', '<', $end)->where('starts_at', '>', $start->subMinutes(600))
            ->get()->first(fn ($a) => $a->endsAt()->greaterThan($start));
        if ($booking) {
            throw ValidationException::withMessages(['starts_at' => 'Un rendez-vous existe déjà sur ce créneau : déplacez-le ou annulez-le d’abord.']);
        }
        if (self::blockClash($tenant->id, (int) $data['user_id'], $start, $end)) {
            throw ValidationException::withMessages(['starts_at' => 'Ce créneau est déjà bloqué.']);
        }

        return AppointmentBlock::create([
            'tenant_id' => $tenant->id, 'user_id' => $data['user_id'], 'starts_at' => $start,
            'duration_minutes' => (int) $data['duration_minutes'],
            'kind' => in_array($data['kind'], AppointmentBlock::KINDS, true) ? $data['kind'] : 'pause',
            'note' => $data['note'] ?? null, 'created_by_user_id' => $byUserId,
        ]);
    }

    public static function blockClash(int $tenantId, int $userId, CarbonInterface $start, CarbonInterface $end): ?AppointmentBlock
    {
        return AppointmentBlock::where('tenant_id', $tenantId)->where('user_id', $userId)
            ->where('starts_at', '<', $end)->where('starts_at', '>', $start->copy()->subMinutes(1500))
            ->get()->first(fn (AppointmentBlock $b) => $b->endsAt()->greaterThan($start));
    }

    /** @return array<string, mixed> */
    public static function blockToApi(AppointmentBlock $b): array
    {
        return [
            'id' => $b->id,
            'staff_user_id' => $b->user_id,
            'starts_at' => $b->starts_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'duration_minutes' => $b->duration_minutes,
            'kind' => $b->kind,
            'note' => $b->note,
        ];
    }

    /**
     * The day as the agenda needs it: everyone's hours, the holiday if any,
     * the blocks. Extra keys of the day payload, which older apps ignore.
     *
     * @return array<string, mixed>
     */
    public static function dayPayload(Tenant $tenant, string $date): array
    {
        $staff = $tenant->users()->where('users.is_active', true)->pluck('users.id')->map(fn ($id) => (int) $id);
        $plan = self::plans($tenant, [$date], $staff)[$date] ?? [];
        $closure = self::closure($tenant, $date);

        return [
            'closed' => $closure !== null,
            'closed_label' => $closure?->label,
            'schedule' => $staff->map(fn (int $id) => ['staff_user_id' => $id, 'intervals' => $plan[$id] ?? []])->values()->all(),
            'blocks' => self::blocksOn($tenant, $date)->map(fn (AppointmentBlock $b) => self::blockToApi($b))->values()->all(),
        ];
    }

    public static function isTime(mixed $value): bool
    {
        return is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1;
    }
}
