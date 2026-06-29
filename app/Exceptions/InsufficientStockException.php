<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when an outgoing inventory operation requests more stock than
 * is available in inventory layers at the given location.
 */
class InsufficientStockException extends RuntimeException
{
    public function __construct(
        public readonly int $itemId,
        public readonly int $locationId,
        public readonly int|float $available,
        public readonly int|float $requested,
    ) {
        parent::__construct(
            "Stock insuffisant pour l'article #{$itemId} à l'emplacement #{$locationId}: "
            . "disponible={$available}, demandé={$requested}"
        );
    }
}
