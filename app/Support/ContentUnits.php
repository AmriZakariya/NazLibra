<?php

namespace App\Support;

use App\Models\Item;

/**
 * What a stocked unit of a product can contain, and how a quantity of it reads:
 * « 3,45 flacons (690 ml) ». A small fixed list: a free text would make every
 * recipe a guess about what « cc » or « tube » means.
 */
final class ContentUnits
{
    /** key => [singular, plural] (French source, translated for display). */
    public const UNITS = [
        'ml' => ['ml', 'ml'],
        'cl' => ['cl', 'cl'],
        'l' => ['l', 'l'],
        'g' => ['g', 'g'],
        'kg' => ['kg', 'kg'],
        'piece' => ['pièce', 'pièces'],
        'dose' => ['dose', 'doses'],
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::UNITS);
    }

    public static function isValid(?string $unit): bool
    {
        return $unit !== null && isset(self::UNITS[$unit]);
    }

    /** « ml », « pièce(s) » translated, agreeing with the quantity. */
    public static function label(?string $unit, float|int $quantity = 1, ?string $locale = null): string
    {
        if (! self::isValid($unit)) {
            return (string) $unit;
        }
        [$one, $many] = self::UNITS[$unit];

        return Locale::t(abs($quantity) > 1 ? $many : $one, $locale);
    }

    /** « 10 ml », « 2 pièces ». */
    public static function format(float|int $quantity, ?string $unit, ?string $locale = null): string
    {
        return Stock::plain($quantity).' '.self::label($unit, $quantity, $locale);
    }

    /** What the product form offers: key => translated name. */
    public static function options(): array
    {
        $out = [];
        foreach (self::UNITS as $key => [$one]) {
            $out[$key] = Locale::t($one);
        }

        return $out;
    }

    /**
     * Stock of a product the way a person reads it: « 3,45 flacons (690 ml) »
     * when it has a capacity, the bare number otherwise.
     */
    public static function describeStock(Item $product, float|int $stocked): string
    {
        $text = Stock::plain($stocked).' '.Locale::t(abs($stocked) > 1 ? 'unités' : 'unité');
        if ($product->hasCapacity()) {
            $text .= ' ('.self::format($stocked * (float) $product->content_quantity, $product->content_unit).')';
        }

        return $text;
    }
}
