<?php

namespace App\Modules\Sales\Actions;

use App\Modules\Sales\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Support\OrderReservations;
use RuntimeException;

/**
 * The order dies untouched: nothing was ever delivered against it.
 *
 * ⚠ Refused the moment anything has been delivered - that is a different
 * business event, the short-close (CloseSalesOrder). Two events, two actions,
 * mirroring CancelPurchaseOrder's guard style. Neither touches stock or the
 * ledger, because the order never did. SALES-ORDER-ARCHITECTURE.md §5.4.
 *
 * ⚠ C-SO-6 (Joy): erasing a commitment is a management decision - the route
 * sits behind `sales.orders.cancel`, Owner and Manager only.
 */
class CancelSalesOrder
{
    public function handle(SalesOrder $order): SalesOrder
    {
        if ($order->status === SalesOrderStatus::Cancelled) {
            throw new RuntimeException("Sales order {$order->order_number} is already cancelled.");
        }

        if ($order->status !== SalesOrderStatus::Open) {
            throw new RuntimeException("Sales order {$order->order_number} has deliveries against it - close it short instead of cancelling.");
        }

        // ⚠ Status alone could lie if a recompute ever regressed a partially
        // delivered order to Open; the lines are the truth. Belt and braces,
        // the hasNoOutstandingReceivedStock() instinct from the PO.
        $order->loadMissing('lines');

        if ($order->lines->contains(fn ($line) => (float) $line->quantity_delivered > 0)) {
            throw new RuntimeException("Sales order {$order->order_number} has deliveries against it - close it short instead of cancelling.");
        }

        $order->update(['status' => SalesOrderStatus::Cancelled]);

        // ⭐ B17 (25 Oct 2026, Q4 = A): the stock it held is free again.
        app(OrderReservations::class)->release($order, 'Order cancelled');

        return $order;
    }
}
