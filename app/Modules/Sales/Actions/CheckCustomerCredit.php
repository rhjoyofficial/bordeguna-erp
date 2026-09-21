<?php

namespace App\Modules\Sales\Actions;

use App\Modules\Accounting\Support\Money;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Exceptions\CreditRefused;
use App\Modules\Sales\Models\SalesOrder;

/**
 * ⚠⚠ WHETHER THIS ORDER MAY BE APPROVED ON CREDIT (Joy, 21 Sep 2026: "Block,
 * Owner can override"). Asked at approval, the business decision "may we
 * commit to this?" (settled with ChatGPT), by ApproveSalesOrder, which every
 * approve button and the Approvals page go through.
 *
 * Two rules, and only what the order leaves UNPAID is at stake (its agreed total
 * less money already received for it):
 *
 * 1. A DISTRIBUTOR pays in advance (CRM answer 11): anything unpaid is refused.
 * 2. A customer WITH A CREDIT LIMIT may not go over it: what they owe now (open
 *    invoices and approved undelivered orders, less advances, CustomerExposure)
 *    plus this order's unpaid amount must stay within it. ⚠ No limit set means
 *    no limit is checked (walk-in and website buyers carry none); a limit of 0
 *    means no credit at all.
 */
class CheckCustomerCredit
{
    public function __construct(private readonly CustomerExposure $exposure) {}

    /** @throws CreditRefused */
    public function assertOrderMayProceed(SalesOrder $order): void
    {
        $refusal = $this->refusalFor($order);

        if ($refusal !== null) {
            throw $refusal;
        }
    }

    public function refusalFor(SalesOrder $order): ?CreditRefused
    {
        $customer = $order->customer;

        if ($customer === null) {
            return null;
        }

        $paid = $order->advanceHeld();
        $unpaid = Money::ofMinor(max(0, Money::ofTaka($order->agreedTotal())->minor - max(0, $paid->minor)));

        if ($unpaid->isZero()) {
            return null;
        }

        $owedBefore = Money::ofTaka($this->exposure->handle($customer)['total']);
        $owedAfter = $owedBefore->plus($unpaid);

        if ($customer->type === CustomerType::Distributor) {
            return new CreditRefused(
                "{$customer->name} is a distributor, and distributors pay in advance: {$order->order_number} still has {$unpaid->format()} unpaid. Take the payment first, or an Owner can approve it with a reason.",
                'advance_only', null, $owedBefore, $unpaid, $owedAfter,
            );
        }

        if ($customer->credit_limit === null) {
            return null;
        }

        $limit = Money::ofTaka((string) $customer->credit_limit);

        if ($owedAfter->minor <= $limit->minor) {
            return null;
        }

        return new CreditRefused(
            "{$customer->name} would owe {$owedAfter->format()} with this order, over their credit limit of {$limit->format()}. Take a payment first, or an Owner can approve it with a reason.",
            'credit_limit', $limit, $owedBefore, $unpaid, $owedAfter,
        );
    }
}
