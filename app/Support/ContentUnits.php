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

    /** Extra spellings a sheet may use (English, plurals, long names), already normalised. */
    private const UNIT_ALIASES = [
        'ml' => ['millilitre', 'millilitres', 'milliliter', 'milliliters'],
        'cl' => ['centilitre', 'centilitres', 'centiliter', 'centiliters'],
        'l' => ['litre', 'litres', 'liter', 'liters', 'lt'],
        'g' => ['gr', 'gramme', 'grammes', 'gram', 'grams'],
        'kg' => ['kilo', 'kilos', 'kilogramme', 'kilogrammes', 'kilogram', 'kilograms'],
        'piece' => ['pieces', 'pc', 'pcs', 'unit', 'units', 'unite', 'unites'],
        'dose' => ['doses'],
    ];

    private const CONTAINER_ALIASES = [
        'flacon' => ['flacons', 'bottle', 'bottles', 'bouteille', 'bouteilles'],
        'pot' => ['pots', 'jar', 'jars'],
        'tube' => ['tubes'],
        'boite' => ['boites', 'box', 'boxes'],
        'sachet' => ['sachets', 'bag', 'bags', 'pouch'],
        'bidon' => ['bidons', 'can', 'cans', 'jug', 'jerrycan'],
        'rouleau' => ['rouleaux', 'roll', 'rolls'],
        'paquet' => ['paquets', 'pack', 'packs', 'packet', 'packets'],
        'piece' => ['pieces', 'unit', 'units', 'unite', 'unites'],
    ];

    /** « Pièces », « ML », « flacon » → one comparable spelling (case, accents, trailing dot). */
    private static function fold(string $text): string
    {
        $text = trim($text);
        if (class_exists(\Normalizer::class)) {
            $text = \Normalizer::normalize($text, \Normalizer::FORM_C) ?: $text;
        }
        $text = mb_strtolower($text);
        $ascii = \Illuminate\Support\Str::ascii($text);

        return rtrim($ascii !== '' && preg_match('/[a-z0-9]/', $ascii) ? $ascii : $text, '. ');
    }

    /** @param array<string, array{0: string, 1: string}> $labels @param array<string, list<string>> $aliases */
    private static function lookup(string $text, array $labels, array $aliases): ?string
    {
        $needle = self::fold($text);
        if ($needle === '') {
            return null;
        }
        foreach ($labels as $key => $names) {
            $known = [$key, ...$names, ...($aliases[$key] ?? [])];
            foreach (Locale::SUPPORTED as $locale) {
                foreach ($names as $name) {
                    $known[] = Locale::t($name, $locale);
                }
            }
            foreach ($known as $candidate) {
                if (self::fold($candidate) === $needle) {
                    return $key;
                }
            }
        }

        return null;
    }

    /** The unit key behind what a sheet wrote (« ML », « Pièces », « grams »), null when unknown. */
    public static function parseUnit(string $text): ?string
    {
        return self::lookup($text, self::UNITS, self::UNIT_ALIASES);
    }

    /** The container key behind what a sheet wrote (« Flacon », « boîte », « jar »), null when unknown. */
    public static function parseContainer(string $text): ?string
    {
        return self::lookup($text, self::CONTAINERS, self::CONTAINER_ALIASES);
    }

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
