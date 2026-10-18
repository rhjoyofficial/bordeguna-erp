<?php

namespace App\Modules\Purchasing\Support;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\Approvable;
use App\Modules\Purchasing\Exceptions\PurchaseApprovalRequired;
use App\Modules\Purchasing\Models\PurchaseApproval;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierPayment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * ⭐⭐ THE INTEGRATION POINT between Buy and receive (ruling P4) and purchase
 * approvals (ruling P7), wired in the B+C release (19 Oct 2026).
 *
 * Buy and receive never decides who must approve what. The approval rules
 * (Tk 2 lakh threshold, the exceptions, no self-approval) live INSIDE the
 * bill and payment actions it calls (PurchasingApprovalGate::enforce), so
 * they apply to it automatically. This class answers "did that step stop for
 * approval?" and saves what stopped as a PA- row:
 *
 *  1. the whole purchase commitment (goods + extra costs) is asked of the
 *     gate ({@see commitmentHold()}). When it needs a second person the BILL
 *     carries that commitment as its approval amount, so the bill is what
 *     waits: one PA- row the approver can release, which then posts the bill
 *     exactly as typed (a big purchase cannot dodge the limit by billing the
 *     goods and the carriage separately);
 *  2. the action RETURNED a document waiting for approval ({@see billHold()},
 *     {@see paymentHold()}): an Approvable whose request is pending;
 *  3. the action THREW PurchaseApprovalRequired ({@see holdFrom()}). The
 *     orchestrator keeps the exception and, once its own transaction has
 *     committed, hands it to {@see release()}, which calls the gate's hold()
 *     OUTSIDE that transaction, so the PA- row is never rolled back with a
 *     savepoint and the approvers are told only about what really saved.
 *
 * A hold is not a failure: what was done before it (the order, the receipt)
 * stays, because goods that physically arrived must stay in stock control
 * (ruling P4, physical-receipt exception), and the screen shows what waits.
 * Anything else that throws rolls the whole purchase back.
 *
 * Resolved from the container on every purchase, so a test replaces it with
 * `app()->instance(QuickPurchaseApprovals::class, ...)`.
 */
class QuickPurchaseApprovals
{
    public function __construct(private ?PurchasingApprovalGate $gate = null) {}

    /**
     * The sentence when the WHOLE purchase needs a second person, or null.
     * `$commitment` is the gross goods plus the extra costs, never only what
     * is paid now (ruling P4: a big purchase does not avoid approval by
     * paying a little today). Asked of the gate as a bill of that amount, so
     * the same limit and the same switched-on exceptions apply.
     */
    public function commitmentHold(Money $commitment, Supplier $supplier, User $by, ?Money $discount = null, ?Money $goods = null): ?string
    {
        if (! PurchasingApprovalGate::enabled()) {
            return null;
        }

        $reasons = $this->gate()->assess(new PurchaseDraft(
            kind: 'bill',
            supplier: $supplier,
            amount: $commitment,
            documentDate: Carbon::today(),
            reference: null,
            summary: "Buy and receive from {$supplier->name}",
            discount: $discount,
            discountBase: $goods,
        ));

        return $reasons === [] ? null : implode('; ', array_column($reasons, 'label'));
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
        if (! $e instanceof PurchaseApprovalRequired) {
            return null;
        }

        $labels = implode('; ', array_column($e->reasons, 'label'));

        return match ($e->draft->kind) {
            'bill' => "Bill {$e->draft->reference} needs a second person's approval ({$labels}). Nothing was billed or paid yet: it posts when they approve, and the payment is made after that.",
            'payment' => "The payment needs a second person's approval ({$labels}). The bill is recorded; the payment posts when they approve.",
            default => ucfirst($e->draft->kindLabel())." needs a second person's approval ({$labels}).",
        };
    }

    /**
     * Saves the refused document as pending (PurchasingApprovalGate::hold).
     *
     * ⚠⚠ Call it only AFTER the purchase's own transaction has committed.
     */
    public function release(PurchaseApprovalRequired $required, User $by): PurchaseApproval
    {
        return $this->gate()->hold($required, $by);
    }

    private function gate(): PurchasingApprovalGate
    {
        return $this->gate ??= app(PurchasingApprovalGate::class);
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
