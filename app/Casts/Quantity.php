<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A stock quantity: a whole number when it is one (5, as ever), a decimal
 * only when a product really is stocked in fractions (2.95 bottles).
 *
 * Keeps JSON and every `(int)` reader of an ordinary article exactly as they
 * were while letting consumables carry four decimals.
 */
class Quantity implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): int|float|null
    {
        if ($value === null) {
            return null;
        }

        $number = round((float) $value, 4);

        return abs($number - round($number)) < 0.00005 ? (int) round($number) : $number;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): int|float|null
    {
        return $value === null ? null : round((float) $value, 4);
    }
}
