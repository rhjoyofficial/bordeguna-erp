<?php

namespace App\Modules\Inventory\Enums;

enum StockRequestType: string
{
    case Adjustment = 'adjustment';
    case Transfer = 'transfer';
}
