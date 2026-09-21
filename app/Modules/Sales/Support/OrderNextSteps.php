<?php

namespace App\Modules\Sales\Support;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Sales\Enums\ConsignmentStatus;
use App\Modules\Sales\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\Delivery;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Support\Collection;

/**
 * What is left to do on a sales order, in the order it is done (21 Sep 2026).
 *
 * ⭐ A credit sale is four documents on four screens (order, challan, invoice,
 * receipt), and nothing told the person on one of them which was next, so an
 * order could sit delivered and never billed, or billed and never chased. The
 * replan's rule: documents stay separate, and the order page shows the next
 * action. Read-only: it posts nothing and decides nothing the actions do not.
 *
 * The chain is approve, bill what has left, collect, deliver the rest. Billing
 * comes before delivering the rest because goods that have left are owed for
 * now; the remainder is still on our shelf.
 */
final class OrderNextSteps
{
    /**
     * @param  Collection<int, Delivery>  $deliveries  the order's challans, lines and consignment loaded
     * @return list<array{kind: string, title: string, detail: string, href: string|null, can: bool}>
     */
    public static function for(SalesOrder $order, Collection $deliveries, User $user): array
    {
        $steps = [];
        $approval = $order->approvalRequest;

        // Awaiting an online payment (no approval request yet), cancelled or
        // rejected: the page already says so, and there is no next step.
        if ($approval === null || $order->status === SalesOrderStatus::Cancelled || $approval->status === ApprovalStatus::Rejected) {
            return [];
        }

        if ($approval->isPending()) {
            return [[
                'kind' => 'approve',
                'title' => 'Approve the order',
                'detail' => $user->can('sales.orders.approve')
                    ? 'Nothing can be delivered until it is approved.'
                    : 'Waiting for a manager to approve it. Nothing can be delivered before that.',
                'href' => '#approval',
                'can' => $user->can('sales.orders.approve'),
            ]];
        }

        $withCourier = $deliveries->filter(fn (Delivery $delivery) => $delivery->consignment?->status === ConsignmentStatus::Booked);
        $unbilled = $deliveries
            ->reject(fn (Delivery $delivery) => $withCourier->contains('id', $delivery->id))
            ->filter(fn (Delivery $delivery) => $delivery->lines->contains(fn ($line) => (float) $line->quantityUninvoiced() > 0))
            ->values();

        if ($unbilled->isNotEmpty()) {
            $steps[] = [
                'kind' => 'invoice',
                'title' => $unbilled->count() === 1 ? 'Invoice the challan' : 'Invoice the challans',
                'detail' => $unbilled->pluck('delivery_number')->implode(', ').' left the warehouse and '.($unbilled->count() === 1 ? 'is' : 'are').' not billed yet.',
                // One challan opens with only it ticked; several open on the
                // customer, where each is listed to tick.
                'href' => route('sales.invoices.create', array_filter([
                    'customer_id' => $order->customer_id,
                    'delivery_id' => $unbilled->count() === 1 ? $unbilled->first()->id : null,
                ])),
                'can' => $user->can('sales.sell'),
            ];
        }

        if ($withCourier->isNotEmpty()) {
            $steps[] = [
                'kind' => 'parcel',
                'title' => "Record the courier's answer",
                'detail' => $withCourier->pluck('delivery_number')->implode(', ').' '.($withCourier->count() === 1 ? 'is' : 'are').' with the courier. The invoice is made when their answer is recorded on the challan below.',
                'href' => '#challans',
                'can' => $user->can('sales.sell') && $user->can('sales.receipt.record') && $user->can('sales.returns.record'),
            ];
        }

        $unpaid = SalesInvoice::query()
            ->whereHas('lines.deliveryLine.orderLine', fn ($query) => $query->where('sales_order_id', $order->id))
            ->unsettled()
            ->get();

        if ($unpaid->isNotEmpty()) {
            $owed = Money::ofMinor($unpaid->sum(fn (SalesInvoice $invoice) => $invoice->outstanding()->minor));

            $steps[] = [
                'kind' => 'collect',
                'title' => 'Receive payment',
                'detail' => $unpaid->pluck('invoice_number')->implode(', ').' still '.($unpaid->count() === 1 ? 'owes ' : 'owe ').$owed->toTaka().'.',
                'href' => route('sales.receipts.create', ['customer_id' => $order->customer_id]),
                'can' => $user->can('sales.receipt.record'),
            ];
        }

        $left = $order->lines->contains(fn ($line) => (float) $line->quantity_ordered > (float) $line->quantity_delivered);

        if ($left && in_array($order->status, [SalesOrderStatus::Open, SalesOrderStatus::PartiallyDelivered], true)) {
            $steps[] = [
                'kind' => 'deliver',
                'title' => $deliveries->isEmpty() ? 'Deliver the goods' : 'Deliver the rest',
                'detail' => $deliveries->isEmpty()
                    ? 'Approved. Record the challan when the goods leave.'
                    : 'Part of the order is still to go. If the customer will not take it, close the order short.',
                'href' => '#deliver',
                'can' => $user->can('sales.sell'),
            ];
        }

        if ($steps === []) {
            return [[
                'kind' => 'done',
                'title' => 'Nothing left to do',
                'detail' => $order->status === SalesOrderStatus::Closed
                    ? 'Closed short: what was delivered is billed and paid.'
                    : 'Delivered, billed and paid.',
                'href' => null,
                'can' => true,
            ]];
        }

        return $steps;
    }
}
