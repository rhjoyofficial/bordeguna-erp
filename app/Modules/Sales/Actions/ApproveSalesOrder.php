<?php

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Sales\Exceptions\CreditRefused;
use App\Modules\Sales\Exceptions\StockShortfall;
use App\Modules\Sales\Models\CreditOverride;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Support\CreditOverrideAuthority;
use App\Modules\Sales\Support\OrderReservations;
use Illuminate\Support\Facades\DB;
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
    public function __construct(
        private readonly CheckCustomerCredit $credit,
        private readonly OrderReservations $reservations,
    ) {}

    /**
     * ⚠⚠ THE CREDIT RULES ARE CHECKED HERE (21 Sep 2026), the one place every
     * approval passes: the order page and the Approvals page both call this. A
     * refused order can still be approved by somebody holding
     * sales.credit.override who writes a reason, and that is recorded, once,
     * in credit_overrides; without both, the refusal stands.
     *
     * ⭐ B17 (25 Oct 2026, Q4 = A): the approval that COMPLETES also holds the
     * order's stock (OrderReservations). Short of stock it is refused with a
     * sentence and rolls back, unless a holder of sales.stock.override gives
     * $backorderReason: then what is there is held and the rest waits.
     *
     * @throws CreditRefused
     * @throws StockShortfall
     */
    public function handle(SalesOrder $order, User $reviewedBy, ?string $creditOverrideReason = null, ?string $backorderReason = null): SalesOrder
    {
        // ⚠ An online order waiting for its payment has not been sent for
        // approval at all (SSLCommerz slice P2c). There is nothing to confirm yet.
        if ($order->approvalRequest === null) {
            throw new RuntimeException("Sales order {$order->order_number} is waiting for its online payment and is not ready to confirm.");
        }

        if (! $order->approvalRequest->isPending()) {
            throw new RuntimeException("Sales order {$order->order_number} has already been reviewed.");
        }

        $refusal = $this->credit->refusalFor($order);

        // ⭐ 25 Oct 2026 (Q11 = A): a Manager or the Owner already allowed this
        // order on the order screen with their own password and a reason; that
        // still covers it unless the order now leaves more unpaid.
        if ($refusal !== null && CreditOverrideAuthority::coversOrder($order, $refusal)) {
            $refusal = null;
        }

        if ($refusal !== null) {
            $reason = trim((string) $creditOverrideReason);

            if ($reason === '' || ! $reviewedBy->can('sales.credit.override')) {
                throw $refusal;
            }
        }

        $complete = DB::transaction(function () use ($order, $reviewedBy, $refusal, $creditOverrideReason, $backorderReason) {
            if ($refusal !== null) {
                CreditOverride::create([
                    'business_id' => $order->business_id,
                    'customer_id' => $order->customer_id,
                    'sales_order_id' => $order->id,
                    'rule' => $refusal->rule,
                    'credit_limit' => $refusal->creditLimit?->toTaka(),
                    'owed_before' => $refusal->owedBefore->toTaka(),
                    'amount' => $refusal->amount->toTaka(),
                    'owed_after' => $refusal->owedAfter->toTaka(),
                    'reason' => trim((string) $creditOverrideReason),
                    'overridden_by' => $reviewedBy->id,
                ]);
            }

            $complete = $order->approvalRequest->approve($reviewedBy);

            if ($complete) {
                $this->reservations->reserve($order->refresh(), $reviewedBy, $backorderReason);
            }

            return $complete;
        });

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
