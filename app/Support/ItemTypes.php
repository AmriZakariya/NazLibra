<?php

namespace App\Support;

class ItemTypes
{
    /** Activities → physical item types (Service is universal and always excluded from this map). */
    private static array $activities = [
        'bookstore' => [
            'label' => 'Librairie / Papeterie',
            'types' => [
                'book'   => ['label' => 'Livre',    'hint' => 'ISBN, auteur, édition', 'fields' => 'book'],
                'supply' => ['label' => 'Produit',  'hint' => 'Fournitures, papeterie', 'fields' => null],
            ],
        ],
        'cafe' => [
            'label' => 'Café / Restaurant',
            'types' => [
                'drink'  => ['label' => 'Boisson',      'hint' => 'Café, thé, jus, eau…',   'fields' => null],
                'food'   => ['label' => 'Plat / Snack', 'hint' => 'Nourriture préparée',    'fields' => null],
                'supply' => ['label' => 'Produit',      'hint' => 'Stock physique divers',  'fields' => null],
            ],
        ],
        'pharmacy' => [
            'label' => 'Pharmacie / Para-pharmaceutique',
            'types' => [
                'medication' => ['label' => 'Médicament',         'hint' => 'DCI, dosage, conditionnement', 'fields' => null],
                'supply'     => ['label' => 'Para-pharmaceutique','hint' => 'Crèmes, soins, compléments',   'fields' => null],
            ],
        ],
        'clothing' => [
            'label' => 'Habillement / Textile',
            'types' => [
                'clothing' => ['label' => 'Vêtement',   'hint' => 'Taille, couleur, matière', 'fields' => null],
                'supply'   => ['label' => 'Accessoire', 'hint' => 'Stock physique divers',    'fields' => null],
            ],
        ],
        'general' => [
            'label' => 'Commerce général',
            'types' => [
                'supply' => ['label' => 'Produit', 'hint' => 'Stock physique divers', 'fields' => null],
            ],
        ],
    ];

    /** All defined activity keys. */
    public static function activityKeys(): array
    {
        return array_keys(self::$activities);
    }

    /** Label for an activity key. */
    public static function activityLabel(string $key): string
    {
        return self::$activities[$key]['label'] ?? 'Commerce général';
    }

    /** All activities as [key => label] for a select element. */
    public static function activityOptions(): array
    {
        return array_map(fn ($a) => $a['label'], self::$activities);
    }

    /** Physical item types for a given activity (excludes Service). */
    public static function physicalTypes(string $activity): array
    {
        return (self::$activities[$activity] ?? self::$activities['general'])['types'];
    }

    /** All valid type strings for validation (physical types + service). */
    public static function validTypes(string $activity): array
    {
        return array_merge(array_keys(self::physicalTypes($activity)), ['service']);
    }

    /** Default activity key when tenant setting is absent. */
    public static function defaultActivity(): string
    {
        return 'bookstore';
    }
}
