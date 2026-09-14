<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Foundation\Actions\NotifyApprovers;
use App\Modules\Sales\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\SalesOrder;

/**
 * An order that was waiting for its payment, now paid: send it to the people who
 * confirm orders (SSLCommerz slice P2c).
 *
 * ⚠ ONE place, for both ways the money can arrive - the gateway proving it, or
 * somebody recording a bKash transfer by hand because the customer paid that way
 * instead. Found on the walk: only the gateway path did this, so an order paid by
 * hand stayed out of the queue and would have been cancelled as unpaid.
 *
 * ⚠ Only an order never sent for approval, and never a cancelled one. An order
 * already in the queue, approved or rejected is left exactly as it is.
 */
final class SendPaidOrderForApproval
{
    public function handle(SalesOrder $order, User $sentBy): void
    {
        $order->loadMissing('approvalRequest', 'customer');

        if ($order->approvalRequest !== null || $order->status === SalesOrderStatus::Cancelled) {
            return;
        }

        $order->submitForApproval($sentBy);

        app(NotifyApprovers::class)->handle(
            businessId: $order->business_id,
            permission: 'sales.orders.approve',
            summary: "Sales order {$order->order_number} for {$order->customer?->name}, paid",
        );
    }
}
