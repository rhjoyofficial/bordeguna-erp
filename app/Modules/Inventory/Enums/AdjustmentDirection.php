<?php

namespace App\Modules\Inventory\Enums;

enum AdjustmentDirection: string
{
    case Increase = 'increase';
    case Decrease = 'decrease';
}
