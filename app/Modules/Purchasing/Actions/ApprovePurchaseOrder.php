<?php

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Purchasing\Models\PurchaseOrder;
use RuntimeException;

/**
 * Unlike ApproveStockRequest, approving a PO has no domain side effect by
 * itself — it only makes the PO eligible for RecordGoodsReceipt.
 */
class ApprovePurchaseOrder
{
    public function handle(PurchaseOrder $order, User $reviewedBy): PurchaseOrder
    {
        if (! $order->approvalRequest->isPending()) {
            throw new RuntimeException("Purchase order #{$order->id} has already been reviewed.");
        }

        $order->approvalRequest->approve($reviewedBy);

        return $order;
    }
}
