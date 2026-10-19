<?php

namespace App\Modules\Sales\Actions;

use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Sales\Enums\SalesOrderStatus;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Models\DeliveryLine;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;

/**
 * What this customer would owe if everything promised were delivered and
 * billed today: open invoice balances, plus goods already delivered and not
 * yet invoiced, plus the value of approved, undelivered order quantities at
 * their agreed prices.
 *
 * ⚠⚠ F29 (practice run, 17 Oct 2026): a dealer's 5 jars left Savar on a
 * challan and, until the invoice was raised, the exposure read 0 (open
 * invoices 0 + undelivered 0). Goods out of the door are owed whether or not
 * the paperwork has caught up, so they count, at the order's agreed price (or,
 * for a challan with no order, the price this customer would be billed).
 *
 * ⚠ C-SO-3 (Joy, 2026-08-11): SHOWN, NEVER ENFORCED. This number appears next
 * to the credit limit on the order screen so a human can decide; nothing
 * anywhere blocks on it. Enforcement waits on the C-SLS-6 answer.
 *
 * ⚠ Pending orders are excluded on purpose - an unapproved order is not yet a
 * commitment, and counting it would double-charge the exposure the moment the
 * reviewing manager is looking at it.
 */
class CustomerExposure
{
    /**
     * @return array{credit_limit: string|null, open_invoices: string, delivered_not_invoiced: string, undelivered_orders: string, paid_in_advance: string, total: string}
     */
    public function handle(Customer $customer): array
    {
        $deliveredNotInvoiced = $this->deliveredNotInvoiced($customer);

        $openInvoices = SalesInvoice::query()
            ->where('customer_id', $customer->id)
            ->with('allocations')
            ->get()
            ->reduce(
                fn (Money $carry, SalesInvoice $invoice) => $carry->plus($invoice->outstanding()),
                Money::zero(),
            );

        $undelivered = SalesOrder::query()
            ->where('customer_id', $customer->id)
            ->whereIn('status', [SalesOrderStatus::Open, SalesOrderStatus::PartiallyDelivered])
            ->whereHas('approvalRequest', fn ($q) => $q->where('status', ApprovalStatus::Approved))
            ->with('lines')
            ->get()
            ->reduce(
                function (Money $carry, SalesOrder $order) {
                    $remaining = $order->lines->reduce(
                        // ⚠ Less that quantity's share of any agreed discount
                        // (slice 2.5a): exposure is what the customer will owe,
                        // and a bundle saving is part of what they agreed.
                        fn (Money $lineCarry, $line) => $lineCarry->plus(Money::ofMinor(
                            Money::ofProduct($line->quantityRemaining(), (string) $line->unit_price)->minor
                                - $line->discountShare($line->quantityRemaining())->minor
                        )),
                        Money::zero(),
                    );

                    // ⚠ And less the undelivered goods' share of a coupon on the
                    // whole order (slice 2.5b), in proportion to their value.
                    $goods = Money::ofTaka($order->goodsTotal())->minor;
                    $coupon = $goods <= 0 ? 0 : (int) round($order->orderDiscount()->minor * $remaining->minor / $goods);

                    return $carry->plus(Money::ofMinor($remaining->minor - min($coupon, $remaining->minor)));
                },
                Money::zero(),
            );

        // ⭐ Less money the customer has already paid for orders that no invoice
        // has taken yet (SSLCommerz slice P1). Without it a customer who paid for
        // a parcel in full would read as owing its whole value until the courier
        // delivered it.
        $paidInAdvance = SalesOrder::query()
            ->whereIn('id', CustomerReceipt::query()
                ->where('customer_id', $customer->id)
                ->whereNull('reversed_at')
                ->whereNotNull('sales_order_id')
                ->select('sales_order_id'))
            ->get()
            ->reduce(
                fn (Money $carry, SalesOrder $order) => $carry->plus(Money::ofMinor(max(0, $order->advanceHeld()->minor))),
                Money::zero(),
            );

        $total = Money::ofMinor(max(0, $openInvoices->plus($deliveredNotInvoiced)->plus($undelivered)->minor - $paidInAdvance->minor));

        return [
            'credit_limit' => $customer->credit_limit !== null ? (string) $customer->credit_limit : null,
            'open_invoices' => $openInvoices->toTaka(),
            'delivered_not_invoiced' => $deliveredNotInvoiced->toTaka(),
            'undelivered_orders' => $undelivered->toTaka(),
            'paid_in_advance' => $paidInAdvance->toTaka(),
            'total' => $total->toTaka(),
        ];
    }

    /**
     * Delivered and not yet billed (the challan's billable remainder: less
     * anything already invoiced or returned before billing), at the price the
     * invoice will charge.
     */
    private function deliveredNotInvoiced(Customer $customer): Money
    {
        $lines = DeliveryLine::query()
            // ⭐ Corrections wave 1: a reversed challan owes nothing.
            ->whereHas('delivery', fn ($q) => $q->where('customer_id', $customer->id)->whereNull('reversed_at'))
            ->whereRaw('quantity - quantity_invoiced - quantity_returned_uninvoiced > 0')
            ->with(['delivery', 'product', 'orderLine.order.lines'])
            ->get();

        $resolver = app(ResolveSellingPrice::class);

        return $lines->reduce(function (Money $carry, DeliveryLine $line) use ($customer, $resolver) {
            $quantity = $line->quantityUninvoiced();
            $orderLine = $line->orderLine;

            if ($orderLine !== null) {
                $value = Money::ofMinor(
                    Money::ofProduct($quantity, (string) $orderLine->unit_price)->minor - $orderLine->discountShare($quantity)->minor
                );

                // Less these goods' share of a coupon on the whole order, the
                // same proportion the undelivered part takes below.
                $order = $orderLine->order;
                $goods = $order === null ? 0 : Money::ofTaka($order->goodsTotal())->minor;
                $coupon = $goods <= 0 ? 0 : (int) round($order->orderDiscount()->minor * $value->minor / $goods);

                return $carry->plus(Money::ofMinor(max(0, $value->minor - min($coupon, $value->minor))));
            }

            // A challan raised without an order is billed at this customer's
            // price on the day it went out. No price means nothing to add.
            $price = $line->product === null ? null : $resolver->find($line->product, $customer, $quantity, $line->delivery?->delivered_at);

            return $price === null ? $carry : $carry->plus(Money::ofProduct($quantity, (string) $price->unitPrice));
        }, Money::zero());
    }
}
