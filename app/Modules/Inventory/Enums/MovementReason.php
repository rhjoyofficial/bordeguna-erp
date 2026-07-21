<?php

namespace App\Modules\Inventory\Enums;

enum MovementReason: string
{
    case Receipt = 'receipt';
    case Usage = 'usage';
    case Adjustment = 'adjustment';
    case Transfer = 'transfer';
    case PurchaseReturn = 'purchase_return';
}
