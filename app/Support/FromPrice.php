<?php

namespace App\Support;

use App\Models\Item;
use App\Models\ItemVariant;

/**
 * « À partir de » prices: the catalogue price of such an item (or of its
 * variant) is a floor. The till asks for the real amount and may go up, never
 * below; going lower is what a discount is for. The same rule is applied by
 * the app's till and, authoritatively, by both sale-creation paths here.
 */
final class FromPrice
{
    /** Anything closer than half a cent is rounding, not a lower price. */
    private const TOLERANCE = 0.005;

    /** The lowest unit price allowed, or null when the item has no floor. */
    public static function minimum(Item $item, ?ItemVariant $variant = null): ?float
    {
        if (! $item->price_is_from) {
            return null;
        }

        return round($variant ? $variant->price() : (float) $item->sale_price, 2);
    }

    /** True when `$unitPrice` is under the floor. */
    public static function isBelow(Item $item, ?ItemVariant $variant, float $unitPrice): bool
    {
        $floor = self::minimum($item, $variant);

        return $floor !== null && $unitPrice + self::TOLERANCE < $floor;
    }

    /** The French refusal shown to the cashier, or null when the price is allowed. */
    public static function violation(Item $item, ?ItemVariant $variant, float $unitPrice, Money $money): ?string
    {
        if (! self::isBelow($item, $variant, $unitPrice)) {
            return null;
        }

        $name = $variant ? $item->title.' — '.$variant->name : $item->title;

        return '« '.$name.' » est vendu à partir de '.$money->format(self::minimum($item, $variant))
            .' : le prix ne peut pas être plus bas. Pour descendre en dessous, appliquez une remise.';
    }

    /** « dès 300,00 DH » for a price that is a floor, the plain price otherwise. */
    public static function label(Money $money, float|int|string|null $amount, bool $isFrom, string $word = 'dès'): string
    {
        return $isFrom ? $word.' '.$money->format($amount) : $money->format($amount);
    }
}
