<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\AppointmentBlock;
use App\Models\Item;
use App\Models\ItemVariant;
use App\Models\QueueEntry;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The walk-in queue (« file d'attente »): customers without a booking wait
 * their turn; « appeler le suivant » gives the free barber the first one who
 * can be served by them; once sitting, an agenda booking takes over and
 * « Encaisser » is the same as for any booking.
 *
 * A customer who is CALLED holds that barber's time for their estimated
 * length — in the public slots and in the agenda — so nobody books over them.
 */
final class WalkInQueue
{
    /** The shop's day, Y-m-d. */
    public static function today(Tenant $tenant, ?CarbonImmutable $now = null): string
    {
        return ($now ?? CarbonImmutable::now())->setTimezone(Appointments::timezone($tenant))->toDateString();
    }

    /**
     * @param  array{customer_name?: ?string, customer_phone?: ?string, item_id?: ?int, variant_id?: ?int, staff_user_id?: ?int}  $data
     */
    public static function add(Tenant $tenant, array $data, ?CarbonImmutable $now = null): QueueEntry
    {
        $now ??= CarbonImmutable::now();
        $item = ! empty($data['item_id']) ? Item::where('tenant_id', $tenant->id)->where('type', 'service')->find($data['item_id']) : null;
        if (! empty($data['item_id']) && ! $item) {
            throw ValidationException::withMessages(['item_id' => 'Prestation introuvable.']);
        }
        $variant = $item && ! empty($data['variant_id'])
            ? ItemVariant::where('tenant_id', $tenant->id)->where('item_id', $item->id)->find($data['variant_id'])
            : null;
        if (! empty($data['variant_id']) && ! $variant) {
            throw ValidationException::withMessages(['variant_id' => 'Cette variante n’existe pas pour cette prestation.']);
        }
        $staffId = ! empty($data['staff_user_id']) ? (int) $data['staff_user_id'] : null;
        if ($staffId !== null && ! $tenant->users()->whereKey($staffId)->exists()) {
            throw ValidationException::withMessages(['staff_user_id' => 'Cette personne ne fait pas partie de l’équipe.']);
        }

        $name = trim((string) ($data['customer_name'] ?? '')) ?: null;
        $phone = trim((string) ($data['customer_phone'] ?? '')) ?: null;
        // Found by phone (never by name), like an online request.
        $contact = $phone ? PhoneContact::findOrCreate($tenant, $name, $phone, ['file-attente']) : null;
        $date = self::today($tenant, $now);

        return QueueEntry::create([
            'tenant_id' => $tenant->id,
            'ticket_number' => (int) QueueEntry::where('tenant_id', $tenant->id)->where('queue_date', $date)->max('ticket_number') + 1,
            'queue_date' => $date,
            'customer_name' => $name ?? $contact?->name,
            'customer_phone' => $phone,
            'contact_id' => $contact?->id,
            'item_id' => $item?->id,
            'variant_id' => $variant?->id,
            'service_name' => $item ? ($variant ? $item->title.' — '.$variant->name : $item->title) : null,
            'preferred_staff_user_id' => $staffId,
            'duration_minutes' => Appointments::minutesFor(null, $variant, $item),
            'status' => QueueEntry::WAITING,
            'arrived_at' => $now->utc(),
        ]);
    }

    /**
     * Today's open entries in turn order, with the wait each can expect.
     * Yesterday's leftovers are closed first, and a seated customer whose
     * booking was paid (or dropped) leaves the board.
     *
     * @return Collection<int, array{entry: QueueEntry, wait_minutes: ?int, staff_user_id: ?int}>
     */
    public static function board(Tenant $tenant, ?CarbonImmutable $now = null): Collection
    {
        $now ??= CarbonImmutable::now();
        $today = self::today($tenant, $now);

        QueueEntry::where('tenant_id', $tenant->id)->whereIn('status', QueueEntry::OPEN)->where('queue_date', '<', $today)
            ->update(['status' => QueueEntry::LEFT, 'finished_at' => $now->utc()]);
        QueueEntry::where('tenant_id', $tenant->id)->where('status', QueueEntry::SEATED)->with('appointment')->get()
            ->each(function (QueueEntry $e) use ($now): void {
                $status = $e->appointment?->status;
                if ($e->appointment_id === null || in_array($status, ['done', 'cancelled', 'no_show'], true)) {
                    $e->update(['status' => $status === 'done' ? QueueEntry::DONE : QueueEntry::LEFT, 'finished_at' => $now->utc()]);
                }
            });

        $entries = QueueEntry::where('tenant_id', $tenant->id)->whereIn('status', QueueEntry::OPEN)
            ->with(['staff:id,name', 'preferredStaff:id,name', 'appointment:id,status,sale_id'])
            ->orderBy('arrived_at')->orderBy('id')->get();
        $estimates = self::estimates($tenant, $entries->where('status', QueueEntry::WAITING), $now);

        return $entries->map(fn (QueueEntry $e) => [
            'entry' => $e,
            'wait_minutes' => $estimates[$e->id]['wait'] ?? null,
            'staff_user_id' => $estimates[$e->id]['staff'] ?? $e->staff_user_id,
        ])->values();
    }

    /**
     * How long each waiting customer should expect: the durations of those
     * ahead of them, and how long each barber is busy now (their agenda, a
     * pause, a customer already called).
     *
     * @param  Collection<int, QueueEntry>  $waiting  in turn order
     * @return array<int, array{wait: ?int, staff: ?int}>
     */
    public static function estimates(Tenant $tenant, Collection $waiting, CarbonImmutable $now): array
    {
        if ($waiting->isEmpty()) {
            return [];
        }
        $tz = Appointments::timezone($tenant);
        $today = $now->setTimezone($tz)->toDateString();
        $team = OnlineBooking::staff($tenant)->pluck('id')->map(fn ($id) => (int) $id);
        $hours = Schedules::plans($tenant, [$today], $team)[$today] ?? [];
        // At work now (or later today): someone off all day never serves the queue.
        $team = $team->filter(fn (int $id) => ($hours[$id] ?? []) !== [])->values();

        $free = [];
        foreach ($team as $id) {
            $free[$id] = $now;
        }
        foreach (Appointment::where('tenant_id', $tenant->id)->whereIn('status', Appointment::ACTIVE)->whereNotNull('staff_user_id')
            ->where('starts_at', '<=', $now->utc())->where('starts_at', '>', $now->subHours(10)->utc())->get() as $a) {
            $end = CarbonImmutable::instance($a->endsAt());
            if (isset($free[$a->staff_user_id]) && $end->greaterThan($free[$a->staff_user_id])) {
                $free[$a->staff_user_id] = $end;
            }
        }
        foreach (AppointmentBlock::where('tenant_id', $tenant->id)->where('starts_at', '<=', $now->utc())->where('starts_at', '>', $now->subHours(30)->utc())->get() as $b) {
            $end = CarbonImmutable::instance($b->endsAt());
            if (isset($free[$b->user_id]) && $end->greaterThan($free[$b->user_id])) {
                $free[$b->user_id] = $end;
            }
        }
        foreach (QueueEntry::where('tenant_id', $tenant->id)->where('status', QueueEntry::CALLED)->get() as $c) {
            $end = CarbonImmutable::instance($c->called_at)->addMinutes($c->duration_minutes);
            if (isset($free[$c->staff_user_id]) && $end->greaterThan($free[$c->staff_user_id])) {
                $free[$c->staff_user_id] = $end;
            }
        }

        $out = [];
        foreach ($waiting as $entry) {
            $eligible = $entry->preferred_staff_user_id
                ? collect([(int) $entry->preferred_staff_user_id])->filter(fn (int $id) => isset($free[$id]))
                : ServiceStaff::allowed($entry->item_id ? Item::find($entry->item_id) : null, $team)->filter(fn (int $id) => isset($free[$id]));
            if ($eligible->isEmpty()) {
                $out[$entry->id] = ['wait' => null, 'staff' => null];

                continue;
            }
            $staff = $eligible->sortBy(fn (int $id) => $free[$id]->getTimestamp())->first();
            $out[$entry->id] = ['wait' => max(0, (int) ceil(($free[$staff]->getTimestamp() - $now->getTimestamp()) / 60)), 'staff' => $staff];
            $free[$staff] = $free[$staff]->addMinutes($entry->duration_minutes);
        }

        return $out;
    }

    /**
     * « Appeler le suivant » for [staffId]: the first waiting customer this
     * person may serve (they asked for them or for nobody in particular, and
     * the person does the service). Null when nobody is waiting for them.
     */
    public static function callNext(Tenant $tenant, int $staffId, ?CarbonImmutable $now = null): ?QueueEntry
    {
        $now ??= CarbonImmutable::now();
        if (! $tenant->users()->whereKey($staffId)->exists()) {
            throw ValidationException::withMessages(['staff_user_id' => 'Cette personne ne fait pas partie de l’équipe.']);
        }

        return DB::transaction(function () use ($tenant, $staffId, $now): ?QueueEntry {
            $already = QueueEntry::where('tenant_id', $tenant->id)->where('status', QueueEntry::CALLED)->where('staff_user_id', $staffId)->first();
            if ($already) {
                throw ValidationException::withMessages(['staff_user_id' => sprintf('Le n° %d est déjà appelé : installez-le ou marquez-le parti.', $already->ticket_number)]);
            }
            $entry = QueueEntry::where('tenant_id', $tenant->id)->where('status', QueueEntry::WAITING)
                ->where('queue_date', self::today($tenant, $now))
                ->where(fn ($q) => $q->whereNull('preferred_staff_user_id')->orWhere('preferred_staff_user_id', $staffId))
                ->orderBy('arrived_at')->orderBy('id')->lockForUpdate()->get()
                ->first(fn (QueueEntry $e) => $e->item_id === null
                    || ServiceStaff::allowed(Item::find($e->item_id), collect([$staffId]))->isNotEmpty());
            if (! $entry) {
                return null;
            }
            $entry->update(['status' => QueueEntry::CALLED, 'staff_user_id' => $staffId, 'called_at' => $now->utc()]);

            return $entry;
        });
    }

    /** « Installé »: the customer sits down; an agenda booking (arrived) takes over, ready for « Encaisser ». */
    public static function seat(Tenant $tenant, QueueEntry $entry, ?User $by = null, ?CarbonImmutable $now = null): QueueEntry
    {
        abort_unless($entry->tenant_id === $tenant->id, 404);
        if ($entry->status !== QueueEntry::CALLED) {
            throw ValidationException::withMessages(['status' => 'Appelez d’abord le client.']);
        }
        $now ??= CarbonImmutable::now();

        return DB::transaction(function () use ($tenant, $entry, $by, $now): QueueEntry {
            // The booking is a fact (the customer is in the chair), so it is
            // created as it is, not refused over another booking.
            $appointment = Appointment::create([
                'tenant_id' => $tenant->id,
                'contact_id' => $entry->contact_id,
                'customer_name' => $entry->customer_name ?: 'Passage n° '.$entry->ticket_number,
                'customer_phone' => $entry->getRawOriginal('customer_phone'),
                'staff_user_id' => $entry->staff_user_id,
                'item_id' => $entry->item_id,
                'variant_id' => $entry->variant_id,
                'service_name' => $entry->service_name,
                'starts_at' => $now->utc(),
                'duration_minutes' => $entry->duration_minutes,
                'status' => 'arrived',
                'note' => 'File d’attente n° '.$entry->ticket_number,
                'created_by_user_id' => $by?->id,
            ]);
            $entry->update(['status' => QueueEntry::SEATED, 'seated_at' => $now->utc(), 'appointment_id' => $appointment->id]);

            return $entry->refresh();
        });
    }

    /** « Parti »: gone before being served (or never came when called). */
    public static function leave(Tenant $tenant, QueueEntry $entry, ?CarbonImmutable $now = null): QueueEntry
    {
        abort_unless($entry->tenant_id === $tenant->id, 404);
        if (! in_array($entry->status, [QueueEntry::WAITING, QueueEntry::CALLED], true)) {
            throw ValidationException::withMessages(['status' => 'Ce client est déjà installé : terminez son rendez-vous.']);
        }
        $entry->update(['status' => QueueEntry::LEFT, 'finished_at' => ($now ?? CarbonImmutable::now())->utc()]);

        return $entry->refresh();
    }

    /**
     * The chairs held by customers already CALLED: [staff id, start, end].
     * [ignore] is an entry being seated, which must not block itself.
     *
     * @return Collection<int, array{0: int|null, 1: CarbonImmutable, 2: CarbonImmutable}>
     */
    public static function holds(Tenant $tenant, CarbonImmutable $day, ?int $ignore = null): Collection
    {
        return QueueEntry::where('tenant_id', $tenant->id)->where('status', QueueEntry::CALLED)
            ->when($ignore, fn ($q) => $q->whereKeyNot($ignore))
            ->whereBetween('called_at', [$day->subHours(10)->utc(), $day->endOfDay()->utc()])
            ->get()
            ->map(fn (QueueEntry $e) => [
                $e->staff_user_id ? (int) $e->staff_user_id : null,
                CarbonImmutable::instance($e->called_at),
                CarbonImmutable::instance($e->called_at)->addMinutes($e->duration_minutes),
            ]);
    }

    /** The token in the public screen's address, created on first use. */
    public static function tvToken(Tenant $tenant, bool $regenerate = false): string
    {
        $token = data_get($tenant->settings, 'queue.tv_token');
        if ($token && ! $regenerate) {
            return $token;
        }
        $settings = $tenant->settings ?? [];
        $settings['queue']['tv_token'] = $token = Str::random(32);
        $tenant->update(['settings' => $settings]);

        return $token;
    }

    /** What the public screen may show: a ticket number, a first name, a wait. Nothing else. */
    public static function tvPayload(Tenant $tenant, ?CarbonImmutable $now = null): array
    {
        return [
            'shop' => $tenant->name,
            'entries' => self::board($tenant, $now)->map(fn (array $row) => [
                'ticket' => $row['entry']->ticket_number,
                'first_name' => $row['entry']->customer_name ? Str::before(trim($row['entry']->customer_name), ' ') : null,
                'status' => $row['entry']->status,
                'wait_minutes' => $row['wait_minutes'],
            ])->values()->all(),
        ];
    }
}
