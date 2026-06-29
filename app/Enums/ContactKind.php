<?php

namespace App\Enums;

enum ContactKind: string
{
    case Client   = 'client';
    case Supplier = 'supplier';

    public function label(): string
    {
        return match ($this) {
            self::Client   => 'Client',
            self::Supplier => 'Fournisseur',
        };
    }
}
