<?php

namespace App\Enums;

enum ItemType: string
{
    case Supply     = 'supply';
    case Service    = 'service';
    case Book       = 'book';
    case Drink      = 'drink';
    case Food       = 'food';
    case Medication = 'medication';
    case Clothing   = 'clothing';

    /** True for types that track physical stock. */
    public function tracksStock(): bool
    {
        return $this !== self::Service;
    }
}
