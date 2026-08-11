<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Sales\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\SalesOrder;
use RuntimeException;

/**
 * The short-close: the customer took part of the order and will not take the
 * rest. Routine in distribution - 25 of 40 cartons and "that's enough" is a
 * Tuesday, not an anomaly.
 *
 * ⚠ The remaining quantity simply STOPS BEING OWED. No per-line mutation:
 * quantity_ordered minus quantity_delivered on a Closed order is the recorded
 * shortfall, visible forever, and the order book reports it as such. A reason
 * is required, and who closed it is in the activity log.
 *
 * ⚠ Only a partially delivered order closes short. An untouched order cancels
 * (CancelSalesOrder); a delivered order is already terminal. Neither touches
 * stock or the ledger. SALES-ORDER-ARCHITECTURE.md §5.4, C-SO-6.
 */
class CloseSalesOrder
{
    public function handle(SalesOrder $order, User $closedBy, string $reason): SalesOrder
    {
        if (trim($reason) === '') {
            throw new RuntimeException('Closing an order short needs a reason.');
        }

        if ($order->status !== SalesOrderStatus::PartiallyDelivered) {
            throw new RuntimeException("Sales order {$order->order_number} is {$order->status->value} - only a partially delivered order can be closed short.");
        }

        $order->update([
            'status' => SalesOrderStatus::Closed,
            'note' => trim(($order->note ? $order->note."\n" : '')."Closed short by {$closedBy->name}: {$reason}"),
        ]);

        return $order;
    }
}
