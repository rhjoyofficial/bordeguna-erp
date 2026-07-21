<?php

namespace App\Modules\Purchasing\Actions;

use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use RuntimeException;

/**
 * An untouched Open PO cancels outright. A PO with receipts against it only
 * cancels once every received unit has been returned (RecordPurchaseReturn)
 * — otherwise cancelling would leave received stock with no clean paper
 * trail back to a live order.
 */
class CancelPurchaseOrder
{
    public function handle(PurchaseOrder $order): PurchaseOrder
    {
        if ($order->status === PurchaseOrderStatus::Cancelled) {
            throw new RuntimeException("Purchase order #{$order->id} is already cancelled.");
        }

        $order->loadMissing('lines');

        if ($order->status !== PurchaseOrderStatus::Open && ! $order->hasNoOutstandingReceivedStock()) {
            throw new RuntimeException("Purchase order #{$order->id} still has received stock on hand — return it before cancelling.");
        }

        $order->update(['status' => PurchaseOrderStatus::Cancelled]);

        return $order;
    }
}
