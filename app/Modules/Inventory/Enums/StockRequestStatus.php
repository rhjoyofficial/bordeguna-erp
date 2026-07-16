<?php

namespace App\Modules\Inventory\Enums;

enum StockRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
