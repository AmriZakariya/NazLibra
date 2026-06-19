<?php

namespace App\Services\Inventory;

use RuntimeException;

class InsufficientStockException extends RuntimeException
{
    public function __construct(
        public readonly int $itemId,
        public readonly int $locationId,
        public readonly int $available,
        public readonly int $requested,
        string $message = '',
    ) {
        parent::__construct($message ?: sprintf(
            'Stock insuffisant pour l’article %d au magasin %d. Disponible: %d, demandé: %d.',
            $itemId,
            $locationId,
            $available,
            $requested,
        ));
    }
}
