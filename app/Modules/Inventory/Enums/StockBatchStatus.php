<?php

namespace App\Modules\Inventory\Enums;

enum StockBatchStatus: string
{
    case Active = 'active';
    case Depleted = 'depleted';
    case Expired = 'expired';
}
