<?php

namespace App\Enums;

enum ItemStatus: string
{
    case Active     = 'active';
    case OutOfStock = 'out_of_stock';
    case Archived   = 'archived';
    case Inactive   = 'inactive';

    /** True for items still in service (visible in POS / catalogue). */
    public function isVisible(): bool
    {
        return $this === self::Active || $this === self::OutOfStock;
    }

    /** Compute the correct status from type and initial stock quantity. */
    public static function fromTypeAndStock(string $type, int|float $stock): self
    {
        if ($type === ItemType::Service->value || $stock > 0) {
            return self::Active;
        }

        return self::OutOfStock;
    }
}
