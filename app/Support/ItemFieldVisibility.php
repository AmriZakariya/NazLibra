<?php

namespace App\Support;

use App\Models\Tenant;

/**
 * Which identification fields an article shows, by shop activity and type.
 *
 * The forms used to carry a bookshop's fields for everyone: a haircut asked
 * for its ISBN and its barcode. Mirrors the till's item_field_visibility.dart.
 *
 *   ISBN            bookshop products only
 *   Author / brand  products: « Auteur » in a bookshop, « Marque » elsewhere
 *   Barcode, SKU    products
 *   Group           products; a service only once it has variants
 *
 * A hidden field is never erased: a save leaves the stored value alone.
 */
class ItemFieldVisibility
{
    public const LIBRARY = 'library';

    /**
     * @return array{isbn: bool, author: bool, author_label: string, barcode: bool, sku: bool, group: bool}
     */
    public static function for(?string $activity, ?string $type, bool $hasVariants = false): array
    {
        $isService = $type === 'service';
        $isLibrary = BusinessMode::normalize($activity) === self::LIBRARY;

        return [
            'isbn' => $isLibrary && ! $isService,
            'author' => ! $isService,
            'author_label' => $isLibrary ? 'Auteur' : 'Marque',
            'barcode' => ! $isService,
            'sku' => ! $isService,
            'group' => ! $isService || $hasVariants,
        ];
    }

    public static function forTenant(?Tenant $tenant, ?string $type, bool $hasVariants = false): array
    {
        return self::for(BusinessMode::current($tenant)['key'], $type, $hasVariants);
    }
}
