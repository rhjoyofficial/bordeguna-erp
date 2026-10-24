<?php

namespace App\Modules\Sales\Actions;

use App\Modules\Accounting\Support\Money;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Exceptions\CreditRefused;
use App\Modules\Sales\Models\Customer;
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
 *    plus this order's unpaid amount must stay within it. A limit of 0 means no
 *    credit at all.
 * 3. ⭐⭐ A BLANK LIMIT MEANS CASH ONLY (Joy, 25 Oct 2026, Q11 = A) for a TRADE
 *    customer (shop, dealer, corporate): no limit typed is no credit given,
 *    the same as a limit of 0. Until today blank meant "no limit at all".
 *    ⚠ Walk-ins, retail customers (one person buying for themselves: website
 *    and phone orders, paid on delivery), staff (the staff benefit, recovered
 *    from salary) and the proprietor (no cap, D-B) keep blank = not checked:
 *    their unpaid order is cash on delivery or settles by its own rule, not
 *    trade credit, and refusing every COD order at approval would stop the
 *    shop. Named in the final report for Joy to confirm.
 *
 * ⭐ Over the limit, a Manager or the Owner lets it through ON THE SAME SCREEN
 * with their own password and a reason (CreditOverrideAuthority, 25 Oct 2026);
 * the Approvals inbox with a reason stays as a second path.
 *
 * ⭐ The counter asks the SAME two rules of a sale taken on account (21 Sep
 * 2026, refusalForAmount): one place decides what credit a customer may have,
 * whichever screen is asking.
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

        return $this->judge(
            $customer,
            $unpaid,
            $owedBefore,
            $owedBefore->plus($unpaid),
            advanceOnly: "{$customer->name} is a distributor, and distributors pay in advance: {$order->order_number} still has {$unpaid->format()} unpaid. Take the payment first, or a Manager or the Owner can override it with a reason.",
            overLimit: fn (Money $owedAfter, Money $limit) => "{$customer->name} would owe {$owedAfter->format()} with this order, over their credit limit of {$limit->format()}. Take a payment first, or a Manager or the Owner can override it with a reason.",
            cashOnly: "{$customer->name} has no credit limit, so buys for cash only: {$order->order_number} still has {$unpaid->format()} unpaid. Take the payment first, set a credit limit on the customer, or a Manager or the Owner can override it with a reason.",
        );
    }

    /**
     * May this customer owe $amount more? The counter's question for a sale
     * taken on account (21 Sep 2026): the same two rules as an order, with no
     * override - the cashier takes the payment, or an Owner approves a sales
     * order instead.
     *
     * ⚠⚠ `$alreadyOwed` IS REQUIRED ON PURPOSE. The counter asks AFTER the sale's
     * invoice is written, inside the same transaction, so the exact total (VAT
     * and all) is known and a refusal rolls the whole sale back. By then
     * CustomerExposure already counts that invoice, and adding $amount again
     * would charge it twice. A caller asking BEFORE writing anything passes
     * false. There is no default, so no caller can get it wrong by leaving it
     * out.
     */
    public function refusalForAmount(Customer $customer, Money $amount, bool $alreadyOwed): ?CreditRefused
    {
        if ($amount->minor <= 0) {
            return null;
        }

        $owedNow = Money::ofTaka($this->exposure->handle($customer)['total']);

        // ⚠ Exposure never reads below zero (money held for an order can exceed
        // what is owed), so "before" is what is left once this sale is taken
        // back out, floored at zero; "after" is what the exposure itself says.
        [$owedBefore, $owedAfter] = $alreadyOwed
            ? [Money::ofMinor(max(0, $owedNow->minor - $amount->minor)), $owedNow]
            : [$owedNow, $owedNow->plus($amount)];

        return $this->judge(
            $customer,
            $amount,
            $owedBefore,
            $owedAfter,
            advanceOnly: "{$customer->name} is a distributor, and distributors pay in advance: this sale would leave {$amount->format()} unpaid. Take the payment now, or a Manager or the Owner can override it with a reason.",
            overLimit: fn (Money $after, Money $limit) => "{$customer->name} would owe {$after->format()} with this sale, over their credit limit of {$limit->format()}. Take the payment now, or a Manager or the Owner can override it with a reason.",
            cashOnly: "{$customer->name} has no credit limit, so buys for cash only: this sale would leave {$amount->format()} unpaid. Take the payment now, set a credit limit on the customer, or a Manager or the Owner can override it with a reason.",
        );
    }

    /**
     * ⭐ 25 Oct 2026 (Q11 = A): whether a BLANK credit limit means no credit at
     * all for this customer. True for the trade (shop, dealer, corporate);
     * a distributor is advance-only already. See the class note for who keeps
     * blank = not checked, and why.
     */
    public static function blankMeansCashOnly(Customer $customer): bool
    {
        return in_array($customer->type, [CustomerType::Shop, CustomerType::Dealer, CustomerType::Corporate], true);
    }

    /**
     * Whether the change from `$owedBefore` to `$owedAfter` may stand: the
     * invoice's question (25 Oct 2026), asked with the exposure before and
     * after the invoice is written, so goods already counted as delivered and
     * not billed are not charged twice. Only an INCREASE is judged.
     */
    public function refusalForChange(Customer $customer, Money $owedBefore, Money $owedAfter): ?CreditRefused
    {
        $increase = Money::ofMinor($owedAfter->minor - $owedBefore->minor);

        if ($increase->minor <= 0) {
            return null;
        }

        return $this->judge(
            $customer,
            $increase,
            $owedBefore,
            $owedAfter,
            advanceOnly: "{$customer->name} is a distributor, and distributors pay in advance: this invoice would leave {$increase->format()} more unpaid. Take the payment first, or a Manager or the Owner can override it with a reason.",
            overLimit: fn (Money $after, Money $limit) => "{$customer->name} would owe {$after->format()} with this invoice, over their credit limit of {$limit->format()}. Take a payment first, or a Manager or the Owner can override it with a reason.",
            cashOnly: "{$customer->name} has no credit limit, so buys for cash only: this invoice would leave {$increase->format()} more unpaid. Take the payment first, set a credit limit on the customer, or a Manager or the Owner can override it with a reason.",
        );
    }

    /**
     * The two rules themselves, in one place.
     *
     * @param  callable(Money, Money): string  $overLimit  the sentence, given what they would owe and the limit
     */
    private function judge(Customer $customer, Money $unpaid, Money $owedBefore, Money $owedAfter, string $advanceOnly, callable $overLimit, string $cashOnly): ?CreditRefused
    {
        if ($customer->type === CustomerType::Distributor) {
            return new CreditRefused($advanceOnly, 'advance_only', null, $owedBefore, $unpaid, $owedAfter);
        }

        if ($customer->credit_limit === null) {
            // ⭐ 25 Oct 2026 (Q11 = A): blank = cash only for a trade customer.
            return self::blankMeansCashOnly($customer)
                ? new CreditRefused($cashOnly, 'cash_only', Money::zero(), $owedBefore, $unpaid, $owedAfter)
                : null;
        }

        $limit = Money::ofTaka((string) $customer->credit_limit);

        if ($owedAfter->minor <= $limit->minor) {
            return null;
        }

        return new CreditRefused($overLimit($owedAfter, $limit), 'credit_limit', $limit, $owedBefore, $unpaid, $owedAfter);
    }
}
