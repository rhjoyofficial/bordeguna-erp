<?php

namespace App\Modules\Purchasing\Support;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\Approvable;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierPayment;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * ⭐⭐ THE INTEGRATION POINT between Buy and receive (ruling P4) and purchase
 * approvals (ruling P7, branch feat/purchase-approvals, built in parallel).
 *
 * Buy and receive never decides who must approve what. The approval rules
 * (Tk 2 lakh threshold, the exceptions, no self-approval) live INSIDE the
 * bill and payment actions it calls, so they apply to it automatically. This
 * class only answers "did that step stop for approval?", in one of the three
 * ways P7 may say so:
 *
 *  1. before the bill: the whole purchase commitment (goods + extra costs)
 *     needs approval ({@see commitmentHold()}). Default: never, the bill
 *     action decides;
 *  2. the action RETURNED a document waiting for approval ({@see billHold()},
 *     {@see paymentHold()}). Default: the document is Approvable and its
 *     approval request is pending, which works unchanged the day P7 makes
 *     SupplierBill or SupplierPayment Approvable;
 *  3. the action THREW a "needs approval" refusal ({@see holdFrom()}).
 *     Default: no exception is a hold; P7 teaches this method its exception.
 *
 * A hold is not a failure: what was done before it (the order, the receipt)
 * stays, because goods that physically arrived must stay in stock control
 * (ruling P4, physical-receipt exception), and the screen shows what waits.
 * Anything else that throws rolls the whole purchase back.
 *
 * Resolved from the container on every purchase, so P7 (or a test) replaces
 * it with `app()->instance(QuickPurchaseApprovals::class, ...)` or a binding.
 */
class QuickPurchaseApprovals
{
    /**
     * The sentence when the WHOLE purchase needs a second person before it is
     * billed, or null. `$commitment` is the gross goods plus the extra costs,
     * never only what is paid now (ruling P4: a big purchase does not avoid
     * approval by paying a little today).
     */
    public function commitmentHold(Money $commitment, Supplier $supplier, User $by): ?string
    {
        return null;
    }

    public function billHold(SupplierBill $bill): ?string
    {
        return $this->pending($bill)
            ? "Bill {$bill->bill_number} waits for a second person's approval, so it was not paid yet."
            : null;
    }

    public function paymentHold(SupplierPayment $payment): ?string
    {
        return $this->pending($payment)
            ? "Payment #{$payment->id} waits for a second person's approval."
            : null;
    }

    /** The sentence when this exception means "waits for approval", or null for a real refusal. */
    public function holdFrom(Throwable $e): ?string
    {
        return null;
    }

    private function pending(Model $document): bool
    {
        if (! in_array(Approvable::class, class_uses_recursive($document), true)) {
            return false;
        }

        /** @phpstan-ignore-next-line the relation exists on every Approvable model */
        return (bool) $document->approvalRequest()->first()?->isPending();
    }
}
