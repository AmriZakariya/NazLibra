<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class FourDigitPin implements ValidationRule
{
    public const LENGTH = 4;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/\A[0-9]{4}\z/D', $value) !== 1) {
            $fail('Le code PIN doit contenir exactement 4 chiffres.');
        }
    }
}
