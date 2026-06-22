<?php

namespace App\Services\Inventory;

use App\Models\InventoryMovement;

final class MovementDTO
{
    public function __construct(
        public readonly int $tenantId,
        public readonly int $itemId,
        public readonly ?int $variantId,
        public readonly int $locationId,
        public readonly string $type,
        public readonly int $quantityChanged,
        public readonly ?int $userId = null,
        public readonly ?string $referenceType = null,
        public readonly ?int $referenceId = null,
        public readonly ?string $referenceNumber = null,
        public readonly ?string $note = null,
        public readonly ?string $reason = null,
        public readonly ?string $idempotencyKey = null,
        public readonly ?float $unitCost = null,
        public readonly bool $allowNegative = false,
        public readonly ?int $virtualDeviceId = null,
        public readonly ?string $actorNameSnapshot = null,
        public readonly ?string $terminalNameSnapshot = null,
        public readonly ?string $realDevicePlatform = null,
        public readonly ?string $realDeviceBrowser = null,
        public readonly ?string $realDeviceIp = null,
        public readonly ?string $realDeviceUserAgent = null,
    ) {
        if ($quantityChanged === 0) {
            throw new \InvalidArgumentException('Quantity changed cannot be zero.');
        }

        if (! in_array($type, InventoryMovementType::valid(), true)) {
            throw new \InvalidArgumentException("Invalid inventory movement type: {$type}");
        }
    }

    public function totalCost(): ?float
    {
        if ($this->unitCost === null) {
            return null;
        }

        return round($this->unitCost * abs($this->quantityChanged), 4);
    }
}
