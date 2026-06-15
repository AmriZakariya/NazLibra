<?php

namespace App\Services\Inventory;

final class InventoryMovementType
{
    public const INITIAL_STOCK = 'initial_stock';
    public const OPENING_STOCK = 'opening_stock';
    public const ADJUSTMENT = 'adjustment';
    public const SALE = 'sale';
    public const SALE_CANCEL = 'sale_cancel';
    public const CUSTOMER_RETURN = 'customer_return';
    public const REFUND_WITH_RETURN = 'refund_with_return';
    public const REFUND_WITHOUT_RETURN = 'refund_without_return';
    public const RETURN = 'return';
    public const PURCHASE = 'purchase';
    public const PURCHASE_RECEIPT = 'purchase_receipt';
    public const PURCHASE_RETURN = 'purchase_return';
    public const SUPPLIER_RETURN = 'supplier_return';
    public const MANUAL_ADD = 'manual_add';
    public const MANUAL_DEDUCT = 'manual_deduct';
    public const CORRECTION = 'correction';
    public const ITEM_UPDATE = 'item_update';
    public const DAMAGE = 'damage';
    public const LOSS = 'loss';
    public const EXPIRATION = 'expiration';
    public const INTERNAL_USE = 'internal_use';
    public const TRANSFER_OUT = 'transfer_out';
    public const TRANSFER_IN = 'transfer_in';
    public const RESERVATION = 'reservation';
    public const RESERVATION_RELEASE = 'reservation_release';
    public const STOCKTAKE = 'stocktake';
    public const IMPORT = 'import';
    public const MIGRATION = 'migration';

    public static function increasesStock(string $type): bool
    {
        return in_array($type, [
            self::INITIAL_STOCK,
            self::OPENING_STOCK,
            self::ADJUSTMENT,
            self::SALE_CANCEL,
            self::CUSTOMER_RETURN,
            self::REFUND_WITH_RETURN,
            self::RETURN,
            self::PURCHASE,
            self::PURCHASE_RECEIPT,
            self::MANUAL_ADD,
            self::CORRECTION,
            self::ITEM_UPDATE,
            self::TRANSFER_IN,
            self::STOCKTAKE,
            self::IMPORT,
            self::MIGRATION,
            self::RESERVATION_RELEASE,
        ], true);
    }

    public static function decreasesStock(string $type): bool
    {
        return in_array($type, [
            self::SALE,
            self::PURCHASE_RETURN,
            self::SUPPLIER_RETURN,
            self::MANUAL_DEDUCT,
            self::DAMAGE,
            self::LOSS,
            self::EXPIRATION,
            self::INTERNAL_USE,
            self::TRANSFER_OUT,
            self::RESERVATION,
        ], true);
    }

    public static function valid(): array
    {
        return (new \ReflectionClass(self::class))->getConstants();
    }
}
