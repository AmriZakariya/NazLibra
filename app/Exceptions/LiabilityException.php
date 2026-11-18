<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A gift card or a package refused something: unknown code, expired, balance
 * too low, no session left. Carries a stable `reason` the till can act on and
 * a French message the cashier can read.
 */
class LiabilityException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'invalid')
    {
        parent::__construct($message);
    }
}
