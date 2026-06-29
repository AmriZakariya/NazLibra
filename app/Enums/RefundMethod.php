<?php

namespace App\Enums;

enum RefundMethod: string
{
    case Cash    = 'cash';
    case Credit  = 'credit';
    case Account = 'account';

    public function label(): string
    {
        return match ($this) {
            self::Cash    => 'Espèces',
            self::Credit  => 'Avoir',
            self::Account => 'Compte client',
        };
    }
}
