<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\Contact;
use App\Models\Item;
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
            'service_name' => ['nullable', 'string', 'max:190'],
            'note' => ['nullable', 'string', 'max:1000'],
            'status' => ['nullable', Rule::in(Appointment::STATUSES)],
            'location_id' => ['nullable', 'integer'],
        ];
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

        if (array_key_exists('item_id', $data)) {
            $item = $data['item_id'] ? Item::where('tenant_id', $tenant->id)->find($data['item_id']) : null;
            if ($data['item_id'] && ! $item) {
                throw ValidationException::withMessages(['item_id' => 'Prestation introuvable.']);
            }
            $appointment->item_id = $item?->id;
            if ($item && empty($data['service_name'])) {
                $appointment->service_name = $item->title;
            }
        }

        foreach (['customer_name', 'customer_phone', 'service_name', 'note', 'duration_minutes', 'location_id'] as $field) {
            if (array_key_exists($field, $data) && ! ($field === 'customer_name' && $data[$field] === null && $appointment->contact_id)) {
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
        $appointment->duration_minutes ??= 30;

        if (! $appointment->customer_name && ! $appointment->contact_id) {
            throw ValidationException::withMessages(['customer_name' => 'Indiquez le nom du client.']);
        }

        self::assertFree($appointment);
        $appointment->save();

        return $appointment->refresh();
    }

    /** A barber is one chair: refuses an active booking over another one. */
    public static function assertFree(Appointment $appointment): void
    {
        if (! $appointment->staff_user_id || ! in_array($appointment->status, Appointment::ACTIVE, true)) {
            return;
        }

        $start = $appointment->starts_at;
        $end = $start->copy()->addMinutes($appointment->duration_minutes ?: 30);

        $clash = Appointment::query()
            ->where('tenant_id', $appointment->tenant_id)
            ->where('staff_user_id', $appointment->staff_user_id)
            ->whereIn('status', Appointment::ACTIVE)
            ->when($appointment->exists, fn ($q) => $q->whereKeyNot($appointment->id))
            ->where('starts_at', '<', $end)
            // Ends after this one starts. Computed here, not in SQL: adding
            // minutes to a timestamp is spelled differently on SQLite and MySQL.
            ->where('starts_at', '>', $start->copy()->subMinutes(600))
            ->get()
            ->first(fn (Appointment $other) => $other->endsAt()->greaterThan($start));

        if ($clash) {
            $tz = $appointment->tenant_id ? self::timezone(Tenant::find($appointment->tenant_id)) : 'UTC';
            throw ValidationException::withMessages([
                'starts_at' => sprintf(
                    '%s a déjà un rendez-vous de %s à %s (%s).',
                    $clash->staff?->name ?? 'Ce barbier',
                    $clash->starts_at->setTimezone($tz)->format('H:i'),
                    $clash->endsAt()->setTimezone($tz)->format('H:i'),
                    $clash->customer_name ?? 'client',
                ),
            ]);
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
            'service_name' => $a->service_name,
            'note' => $a->note,
            'sale_id' => $a->sale_id,
            'reminder_url' => self::whatsappUrl($a, $tenant),
        ];
    }

    /**
     * A wa.me link with the reminder written: one tap on the phone, the
     * barber's own WhatsApp, nothing to pay or configure.
     */
    public static function whatsappUrl(Appointment $a, Tenant $tenant): ?string
    {
        $raw = $a->getRawOriginal('customer_phone') ?: $a->contact?->getRawOriginal('phone');
        $phone = Phone::internationalize((string) $raw, Phone::defaultCountry($tenant));
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if (! $digits || strlen($digits) < 8) {
            return null;
        }

        $when = $a->starts_at->setTimezone(self::timezone($tenant));
        $message = sprintf(
            'Bonjour %s, nous vous rappelons votre rendez-vous chez %s le %s à %s%s. À bientôt !',
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
