<?php

namespace App\Rules;

use App\Support\UtcDateTime;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class ExplicitOffsetDateTime implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('Le champ :attribute doit être une date RFC3339 avec un fuseau explicite.');

            return;
        }

        try {
            UtcDateTime::parse($value);
        } catch (\InvalidArgumentException) {
            $fail('Le champ :attribute doit utiliser RFC3339 avec Z ou un décalage explicite, par exemple 2026-06-24T10:23:50Z.');
        }
    }
}
