<?php

namespace App\Casts;

use App\Support\Phone;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Stores a phone number the one way ({@see Phone::normalize()}), whoever wrote
 * it, and hands it out the way people read it ({@see Phone::display()}): a
 * ticket, an invoice or the app gets "+212 6 12 34 56 78", the database keeps
 * "+212612345678" for lookups.
 */
class PhoneNumber implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : Phone::display((string) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return Phone::normalize($value === null ? null : (string) $value);
    }
}
