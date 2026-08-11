<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Sales\Models\SalesOrder;
use RuntimeException;

/**
 * Approving an order has no domain side effect by itself - it only makes the
 * order eligible for delivery (C-SO-1). The mirror of ApprovePurchaseOrder.
 */
class ApproveSalesOrder
{
    public function handle(SalesOrder $order, User $reviewedBy): SalesOrder
    {
        if (! $order->approvalRequest->isPending()) {
            throw new RuntimeException("Sales order {$order->order_number} has already been reviewed.");
        }

        $order->approvalRequest->approve($reviewedBy);

        return $order;
    }
}
