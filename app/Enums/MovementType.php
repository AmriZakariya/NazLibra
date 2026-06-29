<?php

namespace App\Enums;

enum MovementType: string
{
    case Sale           = 'sale';
    case Purchase       = 'purchase';
    case CustomerReturn = 'customer_return';
    case SupplierReturn = 'supplier_return';
    case Adjustment     = 'adjustment';
    case Correction     = 'correction';
    case TransferIn     = 'transfer_in';
    case TransferOut    = 'transfer_out';
    case InitialStock   = 'initial_stock';
    case Stocktake      = 'stocktake';
    case Import         = 'import';
    case Damage         = 'damage';
    case Expiry         = 'expiry';
}
