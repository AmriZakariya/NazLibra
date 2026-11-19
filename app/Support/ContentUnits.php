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

    /** What one stocked unit IS (key => [singular, plural], French source). */
    public const CONTAINERS = [
        'flacon' => ['flacon', 'flacons'],
        'pot' => ['pot', 'pots'],
        'tube' => ['tube', 'tubes'],
        'boite' => ['boîte', 'boîtes'],
        'sachet' => ['sachet', 'sachets'],
        'bidon' => ['bidon', 'bidons'],
        'rouleau' => ['rouleau', 'rouleaux'],
        'paquet' => ['paquet', 'paquets'],
        'piece' => ['pièce', 'pièces'],
    ];

    /** @return list<string> */
    public static function containerKeys(): array
    {
        return array_keys(self::CONTAINERS);
    }

    public static function isValidContainer(?string $container): bool
    {
        return $container !== null && isset(self::CONTAINERS[$container]);
    }

    /** « flacon(s) » translated, agreeing with the quantity; « unité(s) » when none is set. */
    public static function containerLabel(?string $container, float|int $quantity = 1, ?string $locale = null): string
    {
        if (! self::isValidContainer($container)) {
            return Locale::t(abs($quantity) > 1 ? 'unités' : 'unité', $locale);
        }
        [$one, $many] = self::CONTAINERS[$container];

        return Locale::t(abs($quantity) > 1 ? $many : $one, $locale);
    }

    /** What the product form offers: key => translated name. */
    public static function containerOptions(): array
    {
        $out = [];
        foreach (self::CONTAINERS as $key => [$one]) {
            $out[$key] = Locale::t($one);
        }

        return $out;
    }

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
        $text = Stock::plain($stocked).' '.self::containerLabel($product->content_container, $stocked);
        if ($product->hasCapacity()) {
            $text .= ' ('.self::format($stocked * (float) $product->content_quantity, $product->content_unit).')';
        }

        return $text;
    }
}
