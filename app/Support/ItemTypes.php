<?php

namespace App\Support;

class ItemTypes
{
    /** Activities → physical item types (Service is universal and always excluded from this map). */
    private static array $activities = [
        'bookstore' => [
            'label' => 'Librairie / Papeterie',
            'types' => [
                'book'   => ['label' => 'Livre',    'plural' => 'Livres', 'hint' => 'ISBN, auteur, édition', 'fields' => 'book'],
                'supply' => ['label' => 'Produit',  'plural' => 'Produits', 'hint' => 'Fournitures, papeterie', 'fields' => null],
            ],
        ],
        'restaurant' => [
            'label' => 'Restaurant',
            'types' => [
                'book'   => ['label' => 'Plat / menu',     'plural' => 'Plats / menus', 'hint' => 'Menu, assiette, snack…', 'fields' => null],
                'supply' => ['label' => 'Produit cuisine', 'plural' => 'Produits cuisine', 'hint' => 'Ingrédients, stock divers', 'fields' => null],
            ],
        ],
        'cafe' => [
            'label' => 'Café / Coffee shop',
            'types' => [
                'book'   => ['label' => 'Boisson / snack', 'plural' => 'Boissons / snacks', 'hint' => 'Café, thé, jus, snack…', 'fields' => null],
                'supply' => ['label' => 'Produit comptoir', 'plural' => 'Produits comptoir', 'hint' => 'Stock physique divers', 'fields' => null],
            ],
        ],
        'pharmacy' => [
            'label' => 'Pharmacie / Para-pharmaceutique',
            'types' => [
                'medication' => ['label' => 'Médicament',         'plural' => 'Médicaments', 'hint' => 'DCI, dosage, conditionnement', 'fields' => null],
                'supply'     => ['label' => 'Para-pharmaceutique','plural' => 'Para-pharmacie', 'hint' => 'Crèmes, soins, compléments',   'fields' => null],
            ],
        ],
        'clothing' => [
            'label' => 'Habillement / Textile',
            'types' => [
                'clothing' => ['label' => 'Vêtement',   'plural' => 'Vêtements', 'hint' => 'Taille, couleur, matière', 'fields' => null],
                'supply'   => ['label' => 'Accessoire', 'plural' => 'Accessoires', 'hint' => 'Stock physique divers',    'fields' => null],
            ],
        ],
        'grocery' => [
            'label' => 'Épicerie / Supérette',
            'types' => [
                'supply' => ['label' => 'Produit', 'plural' => 'Produits', 'hint' => 'Alimentaire, boissons, hygiène', 'fields' => null],
            ],
        ],
        'bakery' => [
            'label' => 'Boulangerie / Pâtisserie',
            'types' => [
                'book'   => ['label' => 'Pain / pâtisserie',    'plural' => 'Pains / pâtisseries', 'hint' => 'Pain, viennoiserie, gâteau…', 'fields' => null],
                'supply' => ['label' => 'Ingrédient / produit', 'plural' => 'Ingrédients / produits', 'hint' => 'Farine, beurre, emballage…',  'fields' => null],
            ],
        ],
        // Mostly services — a haircut, a massage — which every activity has.
        // The physical type is what the salon also sells.
        'beauty' => [
            'label' => 'Beauté / Spa / Coiffure',
            'types' => [
                'supply' => ['label' => 'Produit de soin', 'plural' => 'Produits de soin', 'hint' => 'Shampoing, crème, vernis…', 'fields' => null],
            ],
        ],
        'barber' => [
            'label' => 'Barbier / Coiffure homme',
            'types' => [
                'supply' => ['label' => 'Produit', 'plural' => 'Produits', 'hint' => 'Cire, gel, huile à barbe…', 'fields' => null],
            ],
        ],
        'cosmetics' => [
            'label' => 'Cosmétique / Parfumerie',
            'types' => [
                'book'   => ['label' => 'Parfum / soin',      'plural' => 'Parfums / soins', 'hint' => 'Parfum, soin, coffret…',   'fields' => null],
                'supply' => ['label' => 'Produit cosmétique', 'plural' => 'Produits cosmétiques', 'hint' => 'Maquillage, accessoires…', 'fields' => null],
            ],
        ],
        'electronics' => [
            'label' => 'Téléphonie / Électronique',
            'types' => [
                'book'   => ['label' => 'Appareil',   'plural' => 'Appareils', 'hint' => 'Téléphone, tablette, ordinateur…', 'fields' => null],
                'supply' => ['label' => 'Accessoire', 'plural' => 'Accessoires', 'hint' => 'Coque, chargeur, câble…',          'fields' => null],
            ],
        ],
        'general' => [
            'label' => 'Commerce général',
            'types' => [
                'supply' => ['label' => 'Produit', 'plural' => 'Produits', 'hint' => 'Stock physique divers', 'fields' => null],
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
            'clothing' => 'clothing',
            'grocery' => 'grocery',
            'bakery' => 'bakery',
            'beauty' => 'beauty',
            'barber' => 'barber',
            'cosmetics' => 'cosmetics',
            'electronics' => 'electronics',
            'drugstore', 'retail' => 'general',
            // Only reached by a mode with no arm above — and that would hand a
            // spa or a phone shop the bookshop's "Livre". BusinessModeTest
            // fails for any mode that lands here.
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

    /** Activities that mostly sell time, not goods: the item form opens on a service. */
    public const SERVICE_LED = ['beauty', 'barber'];

    /** Where a shop keeps its own names for its types. */
    public const SETTINGS_KEY = 'catalog.item_types';

    /** Every shop sells products: this one cannot be hidden. */
    public const BASE = 'supply';

    /** A type met on an item the shop's activity does not list (an old import, a changed activity). */
    private const FALLBACK = [
        'supply' => ['label' => 'Produit', 'plural' => 'Produits'],
        'service' => ['label' => 'Service', 'plural' => 'Services'],
        'book' => ['label' => 'Livre', 'plural' => 'Livres'],
        'medication' => ['label' => 'Médicament', 'plural' => 'Médicaments'],
        'clothing' => ['label' => 'Vêtement', 'plural' => 'Vêtements'],
    ];

    /**
     * Every type this shop has, in order, in its own words: Produit and
     * Service for all, plus the activity's own (Livre, Médicament…), named
     * by the activity unless the shop renamed them.
     *
     * @return list<array{value: string, label: string, plural: string, hint: string, visible: bool, locked: bool, fields: ?string, default_label: string, default_plural: string, default_hint: string}>
     */
    public static function forTenant(mixed $tenant): array
    {
        // Asked once per item in a list: worked out once per shop and settings.
        $key = ($tenant?->id ?? 'none').':'.md5((string) json_encode([$tenant?->settings, $tenant?->business_mode ?? null, $tenant?->mode ?? null]));

        return self::$memo[$key] ??= self::build($tenant);
    }

    /** @var array<string, array<int, array<string, mixed>>> */
    private static array $memo = [];

    private static function build(mixed $tenant): array
    {
        $activity = self::activityForTenant($tenant);
        $mode = BusinessMode::current($tenant);
        $overrides = self::overrides($tenant);

        $defaults = [];
        foreach (self::physicalTypes($activity) as $value => $type) {
            $defaults[(string) $value] = [
                'label' => $type['label'],
                'plural' => $type['plural'] ?? $type['label'],
                'hint' => (string) ($type['hint'] ?? ''),
                'fields' => $type['fields'] ?? null,
            ];
        }
        $service = ['service' => [
            'label' => (string) ($mode['type_labels']['service'] ?? 'Service'),
            'plural' => 'Services',
            'hint' => 'Sans stock : coupe, soin, réparation, livraison…',
            'fields' => null,
        ]];
        $defaults = in_array($activity, self::SERVICE_LED, true) ? $service + $defaults : $defaults + $service;

        $types = [];
        foreach ($defaults as $value => $default) {
            $own = $overrides[$value] ?? [];
            $locked = $value === self::BASE;
            $types[] = [
                'value' => $value,
                'label' => self::clean($own['label'] ?? null) ?? $default['label'],
                'plural' => self::clean($own['plural'] ?? null) ?? (self::clean($own['label'] ?? null) ?? $default['plural']),
                'hint' => array_key_exists('hint', $own) && is_string($own['hint']) ? trim($own['hint']) : $default['hint'],
                'visible' => $locked || ! ($own['hidden'] ?? false),
                'locked' => $locked,
                'fields' => $default['fields'],
                'default_label' => $default['label'],
                'default_plural' => $default['plural'],
                'default_hint' => $default['hint'],
            ];
        }

        return $types;
    }

    /** The types offered when creating an item and in filters: the shown ones. */
    public static function visibleForTenant(mixed $tenant): array
    {
        return array_values(array_filter(self::forTenant($tenant), fn (array $t) => $t['visible']));
    }

    /**
     * The shown stock types (no service), keyed by value, as the web's item
     * form and catalogue read them.
     *
     * @return array<string, array{label: string, plural: string, hint: string, fields: ?string}>
     */
    public static function physicalForTenant(mixed $tenant): array
    {
        $out = [];
        foreach (self::visibleForTenant($tenant) as $t) {
            if ($t['value'] !== 'service') {
                $out[$t['value']] = ['label' => $t['label'], 'plural' => $t['plural'], 'hint' => $t['hint'], 'fields' => $t['fields']];
            }
        }

        return $out;
    }

    /** Whether the shop shows its services (it may hide them). */
    public static function servicesShown(mixed $tenant): bool
    {
        return collect(self::visibleForTenant($tenant))->contains('value', 'service');
    }

    /** "Produit" / "Produits": an item's type in this shop's words, hidden or not. */
    public static function label(mixed $tenant, ?string $type, bool $plural = false): string
    {
        $type = (string) $type;
        foreach (self::forTenant($tenant) as $t) {
            if ($t['value'] === $type) {
                return $plural ? $t['plural'] : $t['label'];
            }
        }
        $fallback = self::FALLBACK[$type] ?? null;

        return $fallback ? $fallback[$plural ? 'plural' : 'label'] : \Illuminate\Support\Str::headline($type);
    }

    /** @return array<string, array{label?: string, plural?: string, hint?: string, hidden?: bool}> */
    public static function overrides(mixed $tenant): array
    {
        $own = data_get($tenant?->settings, self::SETTINGS_KEY, []);

        return is_array($own) ? $own : [];
    }

    /**
     * Keeps only what differs from the activity's names, so a shop that
     * changes activity later gets the new activity's words for anything it
     * never renamed.
     *
     * @param  array<string, array<string, mixed>>  $input
     * @return array<string, array<string, mixed>>
     */
    public static function overridesFrom(mixed $tenant, array $input): array
    {
        $out = [];
        foreach (self::forTenant($tenant) as $t) {
            $row = is_array($input[$t['value']] ?? null) ? $input[$t['value']] : [];
            $own = [];
            $label = self::clean($row['label'] ?? null);
            $plural = self::clean($row['plural'] ?? null);
            $hint = is_string($row['hint'] ?? null) ? trim(mb_substr($row['hint'], 0, 120)) : null;
            if ($label !== null && $label !== $t['default_label']) {
                $own['label'] = $label;
            }
            if ($plural !== null && $plural !== $t['default_plural'] && $plural !== $label) {
                $own['plural'] = $plural;
            }
            if ($hint !== null && $hint !== $t['default_hint']) {
                $own['hint'] = $hint;
            }
            if (! $t['locked'] && empty($row['visible'])) {
                $own['hidden'] = true;
            }
            if ($own !== []) {
                $out[$t['value']] = $own;
            }
        }

        return $out;
    }

    private static function clean(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim(preg_replace('/\s+/u', ' ', strip_tags($value)) ?? '');

        return $value === '' ? null : mb_substr($value, 0, 40);
    }

    /**
     * What the app's item form offers, in order: the shown types, Service
     * first where shops mostly sell services.
     *
     * @return list<array{value: string, label: string, plural: string, hint: string}>
     */
    public static function forApp(mixed $tenant): array
    {
        return array_map(fn (array $t) => [
            'value' => $t['value'],
            'label' => $t['label'],
            'plural' => $t['plural'],
            'hint' => $t['hint'],
        ], self::visibleForTenant($tenant));
    }

    /**
     * Every type's name, hidden ones too: an item already of a hidden type
     * still shows its name on the app.
     *
     * @return array<string, array{label: string, plural: string}>
     */
    public static function namesForApp(mixed $tenant): array
    {
        $names = [];
        foreach (self::forTenant($tenant) as $t) {
            $names[$t['value']] = ['label' => $t['label'], 'plural' => $t['plural']];
        }

        return $names;
    }

    /** Default activity key when tenant setting is absent. */
    public static function defaultActivity(): string
    {
        return 'bookstore';
    }
}
