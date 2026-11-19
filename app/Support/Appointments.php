<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\Contact;
use App\Models\Item;
use App\Models\ItemVariant;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The agenda: one set of rules for the web page and the app.
 *
 * A barber cannot be booked twice for the same minutes. That is the only hard
 * rule; everything else — opening hours, breaks — the shop knows better than
 * a setting would, so it is left to the person booking.
 */
class Appointments
{
    /** @return array<string, mixed> */
    public static function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'starts_at' => [$required, 'date'],
            'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:600'],
            'staff_user_id' => ['nullable', 'integer'],
            'contact_id' => ['nullable', 'integer'],
            'customer_name' => ['nullable', 'string', 'max:160'],
            'customer_phone' => ['nullable', 'string', 'max:40'],
            'item_id' => ['nullable', 'integer'],
            'variant_id' => ['nullable', 'integer'],
            'service_name' => ['nullable', 'string', 'max:190'],
            'note' => ['nullable', 'string', 'max:1000'],
            'status' => ['nullable', Rule::in(Appointment::STATUSES)],
            'location_id' => ['nullable', 'integer'],
            // « Hors horaires »: the person booking confirms the time is outside
            // the person's hours (or on a day off / holiday).
            'outside_hours' => ['nullable', 'boolean'],
        ];
    }

    /** What an appointment lasts when nobody typed a length. */
    public const DEFAULT_MINUTES = 30;

    /**
     * How long a booking lasts: what was typed, else the chosen variant's own
     * length, else the service's, else the default. Pure, so every path (web,
     * app, public page) answers the same.
     */
    public static function minutesFor(?int $typed, ?ItemVariant $variant, ?Item $item, int $default = self::DEFAULT_MINUTES): int
    {
        return $typed ?: ($variant?->duration_minutes ?: ($item?->duration_minutes ?: $default));
    }

    /**
     * Creates or updates, checking every id belongs to this shop.
     *
     * @param  array<string, mixed>  $data  validated against rules()
     */
    public static function save(Tenant $tenant, array $data, ?User $by, ?Appointment $appointment = null): Appointment
    {
        $appointment ??= new Appointment(['tenant_id' => $tenant->id, 'status' => 'booked', 'created_by_user_id' => $by?->id]);

        if (array_key_exists('staff_user_id', $data)) {
            $staffId = $data['staff_user_id'];
            if ($staffId !== null && ! $tenant->users()->whereKey($staffId)->exists()) {
                throw ValidationException::withMessages(['staff_user_id' => 'Ce barbier ne fait pas partie de l’équipe.']);
            }
            $appointment->staff_user_id = $staffId;
        }

        if (array_key_exists('contact_id', $data)) {
            $contact = $data['contact_id']
                ? Contact::where('tenant_id', $tenant->id)->find($data['contact_id'])
                : null;
            if ($data['contact_id'] && ! $contact) {
                throw ValidationException::withMessages(['contact_id' => 'Client introuvable.']);
            }
            $appointment->contact_id = $contact?->id;
            // The file's name and phone, unless the booking says otherwise.
            if ($contact) {
                $appointment->customer_name = $data['customer_name'] ?? $contact->name;
                $appointment->customer_phone = $data['customer_phone'] ?? $contact->getRawOriginal('phone');
            }
        }

        if (array_key_exists('item_id', $data) || array_key_exists('variant_id', $data)) {
            $itemId = array_key_exists('item_id', $data) ? $data['item_id'] : $appointment->item_id;
            $item = $itemId ? Item::where('tenant_id', $tenant->id)->find($itemId) : null;
            if ($itemId && ! $item) {
                throw ValidationException::withMessages(['item_id' => 'Prestation introuvable.']);
            }

            // The size / length chosen on the service (« 1 h », « Long »). It
            // must be one of THIS service's, or its price and length would
            // be another service's.
            $variantId = array_key_exists('variant_id', $data)
                ? $data['variant_id']
                : ($item?->id === $appointment->item_id ? $appointment->variant_id : null);
            $variant = $variantId && $item
                ? ItemVariant::where('tenant_id', $tenant->id)->where('item_id', $item->id)->find($variantId)
                : null;
            if ($variantId && ! $variant) {
                throw ValidationException::withMessages(['variant_id' => 'Cette variante n’existe pas pour cette prestation.']);
            }

            $appointment->item_id = $item?->id;
            $appointment->variant_id = $variant?->id;
            if ($item && empty($data['service_name'])) {
                $appointment->service_name = $variant ? $item->title.' — '.$variant->name : $item->title;
            }
            // The variant's own length, else the service's, unless the booking
            // says otherwise.
            $typed = ! empty($data['duration_minutes']) ? (int) $data['duration_minutes'] : null;
            if ($typed === null && ($variant?->duration_minutes || $item?->duration_minutes)) {
                $appointment->duration_minutes = self::minutesFor(null, $variant, $item);
            }
        }

        foreach (['customer_name', 'customer_phone', 'service_name', 'note', 'duration_minutes', 'location_id'] as $field) {
            // A blank length leaves what the service / variant gave; a blank
            // name leaves the contact's.
            if (array_key_exists($field, $data)
                && ! ($field === 'customer_name' && $data[$field] === null && $appointment->contact_id)
                && ! ($field === 'duration_minutes' && $data[$field] === null && $appointment->duration_minutes)) {
                $appointment->{$field} = $data[$field];
            }
        }
        if (array_key_exists('starts_at', $data)) {
            // The clock the shop sees: a time typed "10:30" is 10:30 in the
            // shop's timezone, wherever the server is.
            $appointment->starts_at = CarbonImmutable::parse($data['starts_at'], self::timezone($tenant))->utc();
        }
        if (! empty($data['status'])) {
            $appointment->status = $data['status'];
        }
        $appointment->duration_minutes ??= self::DEFAULT_MINUTES;

        if (! $appointment->customer_name && ! $appointment->contact_id) {
            throw ValidationException::withMessages(['customer_name' => 'Indiquez le nom du client.']);
        }

        self::assertFree($appointment, ! empty($data['outside_hours']));
        $appointment->save();

        return $appointment->refresh();
    }

    /**
     * A barber is one chair: refuses an active booking over another one, or
     * over a pending online request (« à confirmer » holds the chair too, as
     * on the public page). A booking made without a barber still occupies
     * someone: if the others cannot absorb it, it would land on this one.
     * Confirming a request, or editing a booking, never clashes with itself.
     */
    public static function assertFree(Appointment $appointment, bool $allowOutsideHours = false): void
    {
        if (! $appointment->staff_user_id || ! in_array($appointment->status, Appointment::ACTIVE, true)) {
            return;
        }

        $start = $appointment->starts_at;
        $end = $start->copy()->addMinutes($appointment->duration_minutes ?: 30);
        $tenant = Tenant::find($appointment->tenant_id);
        $tz = self::timezone($tenant);

        $clash = Appointment::query()
            ->where('tenant_id', $appointment->tenant_id)
            ->where('staff_user_id', $appointment->staff_user_id)
            ->whereIn('status', OnlineBooking::HOLDING)
            ->when($appointment->exists, fn ($q) => $q->whereKeyNot($appointment->id))
            ->where('starts_at', '<', $end)
            // Ends after this one starts. Computed here, not in SQL: adding
            // minutes to a timestamp is spelled differently on SQLite and MySQL.
            ->where('starts_at', '>', $start->copy()->subMinutes(600))
            ->get()
            ->first(fn (Appointment $other) => $other->endsAt()->greaterThan($start));

        if ($clash) {
            throw ValidationException::withMessages([
                'starts_at' => sprintf(
                    $clash->status === 'requested'
                        ? '%s a déjà une demande en ligne à confirmer de %s à %s (%s).'
                        : '%s a déjà un rendez-vous de %s à %s (%s).',
                    $clash->staff?->name ?? 'Ce barbier',
                    $clash->starts_at->setTimezone($tz)->format('H:i'),
                    $clash->endsAt()->setTimezone($tz)->format('H:i'),
                    $clash->customer_name ?? 'client',
                ),
            ]);
        }

        // A walk-in already called is about to sit in this chair.
        if ($tenant) {
            $day = CarbonImmutable::instance($start)->setTimezone($tz)->startOfDay();
            $walkIn = WalkInQueue::holds($tenant, $day)->first(fn (array $h) => $h[0] === (int) $appointment->staff_user_id
                && $h[1]->lessThan($end) && $h[2]->greaterThan($start));
            if ($walkIn) {
                throw ValidationException::withMessages([
                    'starts_at' => sprintf(
                        '%s reçoit un client de la file d’attente de %s à %s.',
                        User::find($appointment->staff_user_id)?->name ?? 'Ce barbier',
                        $walkIn[1]->setTimezone($tz)->format('H:i'),
                        $walkIn[2]->setTimezone($tz)->format('H:i'),
                    ),
                ]);
            }
        }

        // A pause, a day off, a training: not bookable, whatever else is free.
        if ($block = Schedules::blockClash($appointment->tenant_id, (int) $appointment->staff_user_id, $start, $end)) {
            throw ValidationException::withMessages([
                'starts_at' => sprintf(
                    '%s est indisponible de %s à %s (%s).',
                    $appointment->staff?->name ?? User::find($appointment->staff_user_id)?->name ?? 'Ce barbier',
                    $block->starts_at->setTimezone($tz)->format('H:i'),
                    $block->endsAt()->setTimezone($tz)->format('H:i'),
                    Schedules::BLOCK_LABELS[$block->kind] ?? $block->kind,
                ),
            ]);
        }

        // Outside the person's hours: allowed, but only knowingly.
        if ($tenant && ! $allowOutsideHours) {
            $localStart = CarbonImmutable::instance($start)->setTimezone($tz);
            $date = $localStart->toDateString();
            if (Schedules::enforced($tenant, (int) $appointment->staff_user_id, $date)
                && ! Schedules::covers(Schedules::intervals($tenant, (int) $appointment->staff_user_id, $date), $localStart, CarbonImmutable::instance($end)->setTimezone($tz))) {
                throw ValidationException::withMessages([
                    'outside_hours' => sprintf(
                        'Hors horaires : %s ne travaille pas le %s de %s à %s. Confirmez pour réserver quand même.',
                        User::find($appointment->staff_user_id)?->name ?? 'Ce barbier',
                        $localStart->format('d/m'),
                        $localStart->format('H:i'),
                        CarbonImmutable::instance($end)->setTimezone($tz)->format('H:i'),
                    ),
                ]);
            }
        }

        // Bookings with nobody named: each takes one free barber of the team.
        if ($tenant) {
            $team = OnlineBooking::staff($tenant)->pluck('id')->map(fn ($id) => (int) $id);
            $day = CarbonImmutable::instance($start)->setTimezone($tz)->startOfDay();
            if ($team->contains((int) $appointment->staff_user_id)
                && OnlineBooking::firstFree(
                    collect([(int) $appointment->staff_user_id]), $team,
                    OnlineBooking::busy($tenant, $day, $appointment->exists ? $appointment->id : null),
                    CarbonImmutable::instance($start), CarbonImmutable::instance($end),
                ) === null) {
                throw ValidationException::withMessages([
                    'starts_at' => 'Des rendez-vous sans barbier occupent déjà ce créneau : attribuez-les à quelqu’un ou choisissez un autre horaire.',
                ]);
            }
        }
    }

    /** The day's bookings, in the shop's day, oldest first. */
    public static function forDay(Tenant $tenant, string $date): Collection
    {
        $tz = self::timezone($tenant);
        $day = CarbonImmutable::parse($date, $tz)->startOfDay();

        return Appointment::query()
            ->with(['staff:id,name,avatar_color', 'contact:id,name,phone,notes'])
            ->where('tenant_id', $tenant->id)
            ->whereBetween('starts_at', [$day->utc(), $day->endOfDay()->utc()])
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * How many bookings each day of [date]'s week (Monday first) holds,
     * cancelled ones aside: the dots on the app's week strip.
     *
     * @return array<string, int>  Y-m-d => count
     */
    public static function weekCounts(Tenant $tenant, string $date): array
    {
        $tz = self::timezone($tenant);
        $monday = CarbonImmutable::parse($date, $tz)->startOfWeek(CarbonImmutable::MONDAY);
        $counts = [];
        for ($i = 0; $i < 7; $i++) {
            $counts[$monday->addDays($i)->toDateString()] = 0;
        }
        Appointment::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', '!=', 'cancelled')
            ->whereBetween('starts_at', [$monday->utc(), $monday->addDays(7)->utc()])
            ->pluck('starts_at')
            ->each(function ($start) use (&$counts, $tz): void {
                $day = $start->setTimezone($tz)->toDateString();
                if (isset($counts[$day])) {
                    $counts[$day]++;
                }
            });

        return $counts;
    }

    /** @return array<string, mixed> */
    public static function toApi(Appointment $a, Tenant $tenant): array
    {
        return [
            'id' => $a->id,
            'starts_at' => $a->starts_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'duration_minutes' => $a->duration_minutes,
            'status' => $a->status,
            'staff_user_id' => $a->staff_user_id,
            'staff_name' => $a->staff?->name,
            'contact_id' => $a->contact_id,
            'customer_name' => $a->customer_name ?? $a->contact?->name,
            'customer_phone' => $a->customer_phone,
            'customer_notes' => $a->contact?->notes,
            'item_id' => $a->item_id,
            'variant_id' => $a->variant_id,
            'service_name' => $a->service_name,
            'note' => $a->note,
            'sale_id' => $a->sale_id,
            'reminder_url' => self::whatsappUrl($a, $tenant),
            'confirmation_url' => self::whatsappUrl($a, $tenant, confirmation: true),
        ];
    }

    /**
     * A wa.me link with the reminder (or the confirmation of an online
     * request) written: one tap on the phone, the barber's own WhatsApp,
     * nothing to pay or configure.
     */
    public static function whatsappUrl(Appointment $a, Tenant $tenant, bool $confirmation = false): ?string
    {
        $raw = $a->getRawOriginal('customer_phone') ?: $a->contact?->getRawOriginal('phone');
        $phone = Phone::internationalize((string) $raw, Phone::defaultCountry($tenant));
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if (! $digits || strlen($digits) < 8) {
            return null;
        }

        $when = $a->starts_at->setTimezone(self::timezone($tenant));
        $message = sprintf(
            $confirmation
                ? 'Bonjour %s, votre rendez-vous chez %s le %s à %s%s est confirmé. À bientôt !'
                : 'Bonjour %s, nous vous rappelons votre rendez-vous chez %s le %s à %s%s. À bientôt !',
            $a->customer_name ?? $a->contact?->name ?? '',
            $tenant->name,
            $when->format('d/m'),
            $when->format('H:i'),
            $a->staff ? ' avec '.$a->staff->name : '',
        );

        return 'https://wa.me/'.$digits.'?text='.rawurlencode($message);
    }

    public static function timezone(?Tenant $tenant): string
    {
        return $tenant?->timezone ?: 'Africa/Casablanca';
    }
}
