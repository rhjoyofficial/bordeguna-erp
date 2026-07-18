<?php

namespace App\Modules\Purchasing\Actions;

use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Purchasing\Enums\PaymentStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use RuntimeException;

/**
 * Purely a status flag for day-to-day "did we pay this" visibility — no
 * ledger, no payment records. Real AP tracking is a Phase 4 (Accounting)
 * concern that will need to reconcile with whatever this flag says.
 */
class UpdatePurchaseOrderPaymentStatus
{
    public function handle(PurchaseOrder $order, PaymentStatus $status): PurchaseOrder
    {
        if ($order->approvalRequest->status !== ApprovalStatus::Approved) {
            throw new RuntimeException("Purchase order #{$order->id} isn't approved yet.");
        }

        $order->update(['payment_status' => $status]);

        return $order;
    }
}
