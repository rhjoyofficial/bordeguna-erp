<?php

namespace App\Modules\Purchasing\Actions;

use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;
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
        return DB::transaction(function () use ($order) {
            // ⚠⚠ Re-read under a lock, the same lock RecordGoodsReceipt takes.
            // Checking the caller's copy let a receipt and a cancel both pass:
            // stock arrived against a cancelled order. The re-read is what
            // holds on SQLite, where the lock is a no-op.
            $current = PurchaseOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($current->status === PurchaseOrderStatus::Cancelled) {
                throw new RuntimeException("Purchase order #{$order->id} is already cancelled.");
            }

            $current->load('lines');

            if ($current->status !== PurchaseOrderStatus::Open && ! $current->hasNoOutstandingReceivedStock()) {
                throw new RuntimeException("Purchase order #{$order->id} still has received stock on hand — return it before cancelling.");
            }

            $current->update(['status' => PurchaseOrderStatus::Cancelled]);

            return $order->refresh();
        });
    }
}
