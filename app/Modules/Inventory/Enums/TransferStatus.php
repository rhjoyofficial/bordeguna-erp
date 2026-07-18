<?php

namespace App\Modules\Inventory\Enums;

enum TransferStatus: string
{
    case InTransit = 'in_transit';
    case Received = 'received';
}
