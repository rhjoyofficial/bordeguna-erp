<?php

namespace App\Modules\Inventory\Enums;

enum TransferStatus: string
{
    case InTransit = 'in_transit';
    case Received = 'received';
    // ⭐ Corrections wave 1 (20 Oct 2026): taken off the road before it
    // arrived; its stock is back on the batches it left.
    case Cancelled = 'cancelled';
}
