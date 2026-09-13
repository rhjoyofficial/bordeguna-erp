<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Sales\Models\SalesOrder;
use RuntimeException;

/**
 * Approving an order makes it eligible for delivery (C-SO-1). The mirror of
 * ApprovePurchaseOrder.
 *
 * ⭐ And, for an order that carries a delivery promise, approval IS the
 * confirmation the promise counts from (D24). So the one side effect it has is
 * writing the date the customer can now expect: confirmation plus the slower
 * end of the window the order was promised (Joy, 13 September).
 */
class ApproveSalesOrder
{
    public function handle(SalesOrder $order, User $reviewedBy): SalesOrder
    {
        if (! $order->approvalRequest->isPending()) {
            throw new RuntimeException("Sales order {$order->order_number} has already been reviewed.");
        }

        $complete = $order->approvalRequest->approve($reviewedBy);

        // ⚠ Only when the approval is COMPLETE. A multi-stage approval that has
        // only advanced a stage has not confirmed anything to the customer yet.
        if ($complete && $order->delivery_promise_max_hours !== null) {
            $order->update([
                'expected_date' => now()->addHours((int) $order->delivery_promise_max_hours)->toDateString(),
            ]);
        }

        return $order;
    }
}
