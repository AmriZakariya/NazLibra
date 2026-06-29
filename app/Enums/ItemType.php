<?php

namespace App\Enums;

enum ItemType: string
{
    case Supply  = 'supply';
    case Service = 'service';
    case Book    = 'book';

    /** True for types that track physical stock. */
    public function tracksStock(): bool
    {
        return $this !== self::Service;
    }
}
