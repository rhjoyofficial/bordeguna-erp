<?php

namespace App\Modules\Purchasing\Enums;

enum PurchaseOrderStatus: string
{
    case Open = 'open';
    case PartiallyReceived = 'partially_received';
    case Received = 'received';
    case Cancelled = 'cancelled';
}
