<?php

namespace App\Support;

use App\Models\Contact;
use App\Models\Tenant;

/**
 * Finds the customer file behind a name and a phone typed on a public page
 * or at the desk — and creates it when there is none.
 *
 * A customer is recognised by the PHONE, in E.164, within this shop. Never by
 * the name: two Karims are two customers, and a booking must not attach to a
 * stranger's file (their notes, their packages, their gift cards).
 */
final class PhoneContact
{
    /** The shop's customer with this phone, or null. A number that is not international never matches. */
    public static function find(Tenant $tenant, ?string $phone): ?Contact
    {
        $e164 = self::e164($tenant, $phone);
        if ($e164 === null) {
            return null;
        }

        return Contact::query()
            ->where('tenant_id', $tenant->id)
            ->where('kind', 'client')
            ->where('phone', $e164)
            ->orderBy('id')
            ->first();
    }

    /**
     * The customer for [phone], created with [name] when new. Without a phone
     * there is nothing to recognise them by: null, and the booking keeps the
     * name on its own row.
     *
     * @param  list<string>  $tags
     */
    public static function findOrCreate(Tenant $tenant, ?string $name, ?string $phone, array $tags = []): ?Contact
    {
        if (self::e164($tenant, $phone) === null && blank($phone)) {
            return null;
        }
        $name = trim((string) $name);

        return self::find($tenant, $phone)
            ?? ($name === '' ? null : Contact::create([
                'tenant_id' => $tenant->id,
                'kind' => 'client',
                'name' => $name,
                'phone' => self::e164($tenant, $phone) ?? $phone,
                'tags' => $tags ?: null,
            ]));
    }

    /** E.164 ("+212612345678"), reading a local number in the shop's country; null if it is not one. */
    public static function e164(Tenant $tenant, ?string $phone): ?string
    {
        if (blank($phone)) {
            return null;
        }
        $value = Phone::normalize(Phone::internationalize($phone, Phone::defaultCountry($tenant)));

        return $value !== null && preg_match('/^\+[0-9]{8,15}$/', $value) === 1 ? $value : null;
    }
}
