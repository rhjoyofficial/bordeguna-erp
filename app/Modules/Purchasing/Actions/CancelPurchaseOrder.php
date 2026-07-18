<?php

namespace App\Modules\Purchasing\Actions;

use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use RuntimeException;

/**
 * Only an untouched Open PO can be cancelled outright — once anything has
 * been received against it, cancelling would leave received stock with no
 * clean paper trail. A partially-received PO that's genuinely being
 * abandoned needs a return flow instead (not built yet).
 */
class CancelPurchaseOrder
{
    public function handle(PurchaseOrder $order): PurchaseOrder
    {
        if ($order->status !== PurchaseOrderStatus::Open) {
            throw new RuntimeException("Purchase order #{$order->id} can only be cancelled while Open (no goods received yet).");
        }

        $order->update(['status' => PurchaseOrderStatus::Cancelled]);

        return $order;
    }
}
