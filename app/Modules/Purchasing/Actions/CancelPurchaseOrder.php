<?php

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\CorrectionLog;
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
    /**
     * ⭐ Corrections wave 1 (19 Oct 2026): `$reason` is required from every
     * screen (the controller validates it, FINAL-PLAN 6.1); it goes on the
     * withdrawn approval's note and into the History. Optional here only so
     * the system paths that cancel keep working.
     */
    public function handle(PurchaseOrder $order, ?User $cancelledBy = null, ?ActionReason $reason = null): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $cancelledBy, $reason) {
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

            // ⚠ PUR-006 (audit 14 Oct 2026): a PO cancelled while waiting for
            // approval left its request pending, in every approver's inbox and
            // counter, with Approve still offered on a dead order. It is
            // withdrawn in the same transaction, the payroll way: the engine has
            // no "withdrawn" state, so it closes as rejected with a note saying
            // so, and the decision row names who cancelled.
            $approval = $current->approvalRequest()->first();

            if ($approval !== null && $approval->isPending() && $cancelledBy !== null) {
                $approval->reject($cancelledBy, 'Withdrawn: the purchase order was cancelled.'.($reason === null ? '' : " {$reason->display()}"));
            }

            if ($cancelledBy !== null && $reason !== null) {
                CorrectionLog::event('cancelled', $cancelledBy, $current, ['reason' => $reason->display()]);
            }

            return $order->refresh();
        });
    }
}
