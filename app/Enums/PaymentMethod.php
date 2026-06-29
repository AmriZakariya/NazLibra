<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash     = 'cash';
    case Card     = 'card';
    case Transfer = 'transfer';
    case Credit   = 'credit';

    public function label(): string
    {
        return match ($this) {
            self::Cash     => 'Espèces',
            self::Card     => 'Carte',
            self::Transfer => 'Virement',
            self::Credit   => 'Crédit',
        };
    }
}
