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
    /**
     * First app version that knows « à partir de » and opens the price pad
     * itself. Older builds cannot ask for the price, so refusing their line
     * would leave an offline sale stuck in the outbox with a 422 for good:
     * the mobile API accepts it below the floor, and says so in the journal.
     */
    public const FIRST_APP_VERSION_KNOWING_THE_FLAG = '1.0.17';

    /** Whether the floor is enforced for this request: a web call, or an app new enough to know it. A missing or unreadable version counts as old. */
    public static function enforcedFor(\Illuminate\Http\Request $request): bool
    {
        $version = \App\Support\RequestSnapshot::appClient($request)['version'];
        if ($version === null || ! preg_match('/^(\d+(?:\.\d+){0,3})/', $version, $m)) {
            return false;
        }

        return version_compare($m[1], self::FIRST_APP_VERSION_KNOWING_THE_FLAG, '>=');
    }

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
