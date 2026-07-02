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
        'restaurant' => [
            'label' => 'Restaurant',
            'types' => [
                'book'   => ['label' => 'Plat / menu',     'hint' => 'Menu, assiette, snack…', 'fields' => null],
                'supply' => ['label' => 'Produit cuisine', 'hint' => 'Ingrédients, stock divers', 'fields' => null],
            ],
        ],
        'cafe' => [
            'label' => 'Café / Coffee shop',
            'types' => [
                'book'   => ['label' => 'Boisson / snack', 'hint' => 'Café, thé, jus, snack…', 'fields' => null],
                'supply' => ['label' => 'Produit comptoir', 'hint' => 'Stock physique divers', 'fields' => null],
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
        $key = self::normalizeActivity($key);

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
        $activity = self::normalizeActivity($activity);

        return (self::$activities[$activity] ?? self::$activities['general'])['types'];
    }

    /** All valid type strings for validation (physical types + service). */
    public static function validTypes(string $activity): array
    {
        return array_merge(array_keys(self::physicalTypes($activity)), ['service']);
    }

    public static function normalizeActivity(?string $activity): string
    {
        $activity = str_replace([' ', '-'], '_', strtolower(trim((string) $activity)));

        $aliases = [
            'library' => 'bookstore',
            'book' => 'bookstore',
            'books' => 'bookstore',
            'livrairie' => 'bookstore',
            'restaurant' => 'restaurant',
            'resto' => 'restaurant',
            'coffee' => 'cafe',
            'coffee_shop' => 'cafe',
            'café' => 'cafe',
            'pharmacie' => 'pharmacy',
            'drugstore' => 'general',
            'droguerie' => 'general',
            'retail' => 'general',
            'commerce' => 'general',
        ];

        $activity = $aliases[$activity] ?? $activity;

        return array_key_exists($activity, self::$activities) ? $activity : self::defaultActivity();
    }

    public static function activityFromBusinessMode(?string $businessMode): string
    {
        return match (BusinessMode::normalize($businessMode)) {
            'restaurant' => 'restaurant',
            'coffee' => 'cafe',
            'pharmacy' => 'pharmacy',
            'library' => 'bookstore',
            'drugstore', 'retail' => 'general',
            default => self::defaultActivity(),
        };
    }

    public static function activityForTenant(mixed $tenant): string
    {
        $explicit = data_get($tenant?->settings, 'store.business_activity');
        $derived = self::activityFromBusinessMode(
            data_get($tenant?->settings, 'company_profile.business_mode')
            ?? $tenant?->business_mode
            ?? $tenant?->mode
            ?? null
        );

        if (is_string($explicit) && trim($explicit) !== '') {
            $normalized = self::normalizeActivity($explicit);

            if ($normalized === self::defaultActivity() && $derived !== self::defaultActivity()) {
                return $derived;
            }

            return $normalized;
        }

        return $derived;
    }

    /** Default activity key when tenant setting is absent. */
    public static function defaultActivity(): string
    {
        return 'bookstore';
    }
}
