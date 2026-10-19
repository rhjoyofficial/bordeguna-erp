<?php

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Accounting\Actions\ReverseJournal;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Foundation\Corrections\ClosedPeriodPass;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Purchasing\Enums\LandedCostStatus;
use App\Modules\Purchasing\Models\LandedCost;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Purchasing\Models\SupplierAdvanceApplication;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierDiscount;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * D-SB-6's correction path: a posted supplier bill is immutable, and undoing
 * one is a new journal that says so, never an edit and never a delete.
 *
 * ⚠⚠ WHY DELETION IS NOT THE ANSWER. Before this existed a posted bill could
 * simply be removed, which left its journal standing with nothing behind it and
 * the purchase order line marked as invoiced forever. The books then disagreed
 * with the documents in a way nothing reconciled, and the trial balance still
 * tied while it happened.
 *
 * Three things move, together, inside one transaction:
 *   1. the journal is reversed, so Accounts Payable and GRNI go back and any
 *      Purchase Price Variance is unwound with them;
 *   2. `quantity_invoiced` is given back to the purchase order lines, so the
 *      goods become billable again;
 *   3. the bill itself is kept and marked, because the paper it represents
 *      really did arrive and the audit trail should say what happened to it.
 *
 * ⚠⚠ REFUSED once the bill has been paid or credited. Unwinding a settled bill
 * would need the payment unwound too, and the honest instrument for money that
 * has already moved is a supplier credit note, not a correction.
 */
class ReverseSupplierBill
{
    public function __construct(
        private readonly ReverseJournal $reverseJournal,
        private readonly PostPurchaseJournals $posting,
    ) {}

    public function handle(SupplierBill $bill, User $reversedBy, ?string $note = null): SupplierBill
    {
        // ⚠ The reversal is dated TODAY, not the bill's date. Back-dating it
        // would move a figure in a month somebody has already reported on, and
        // the period lock exists to stop exactly that.
        // (⭐ 20 Oct 2026: the corrections process, forCorrection() below,
        // reverses on the bill's OWN date while its month is open: Joy's
        // section 9 answer 4. This older path keeps its today.)
        $on = Carbon::today();
        AccountingPeriod::assertOpenFor($on);

        return DB::transaction(function () use ($bill, $reversedBy, $note, $on) {
            // ⚠ Bug hunt 16 Oct 2026: the reversed, paid and credited checks ran
            // on the caller's copy before the transaction, so a payment saved a
            // moment earlier could land on a bill being reversed. Re-read under
            // a lock and checked here, inside the transaction that writes.
            $bill = SupplierBill::query()->whereKey($bill->id)->lockForUpdate()->firstOrFail();
            $this->assertReversible($bill);

            $this->unwind($bill, $reversedBy, $on, "Reversal of supplier bill {$bill->bill_number}");

            $bill->update([
                'reversed_at' => $on,
                'reversed_by' => $reversedBy->id,
                'reversal_note' => $note,
            ]);

            return $bill->fresh(['lines', 'supplier']);
        });
    }

    /**
     * ⭐ Corrections wave 1 (20 Oct 2026): the bill's reversal inside the
     * corrections process (SupplierBillCorrector), on the date the date policy
     * resolved and with the Owner's one-document pass for a closed month.
     *
     * `$settlementsMove`: "Correct paid bill" (FINAL-PLAN 3.3, ChatGPT ruling 7).
     * The payments and credit notes allocated to the bill are NOT reversed;
     * the corrector moves their allocations onto the corrected bill in the
     * same transaction, so the paid and credited refusals do not apply. Every
     * other refusal (discounts, advances, set-offs, carriage sent on, a landed
     * cost's bill) still does.
     *
     * ⚠ The bill is marked reversed HERE, before the corrected bill is
     * recorded, so its number is free (PUR-004) for the corrected one.
     *
     * @throws DomainException|RuntimeException
     */
    public function forCorrection(SupplierBill $bill, User $by, Carbon $on, string $reason, ?ClosedPeriodPass $pass, bool $settlementsMove): ?Journal
    {
        $bill = SupplierBill::query()->whereKey($bill->id)->lockForUpdate()->firstOrFail();
        $this->assertReversible($bill, $settlementsMove);

        AccountingPeriod::assertOpenFor($on, $pass);

        $reversal = $this->unwind($bill, $by, $on, "Reversal of supplier bill {$bill->bill_number}: {$reason}", $pass);

        $bill->forceFill([
            'reversed_at' => $on,
            'reversed_by' => $by->id,
            'reversal_note' => $reason,
        ])->save();

        return $reversal;
    }

    /**
     * Why this bill cannot be reversed, in one sentence, or null when it can.
     * The corrections policy asks this so its blockers and the action agree.
     */
    public function refusal(SupplierBill $bill, bool $settlementsMove = false): ?string
    {
        try {
            $this->assertReversible($bill, $settlementsMove);
        } catch (DomainException|RuntimeException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * The three things that move together: the journal, the invoiced quantity
     * given back to the order lines, and any carriage draft nobody sent on.
     */
    private function unwind(SupplierBill $bill, User $by, Carbon $on, string $memo, ?ClosedPeriodPass $pass = null): ?Journal
    {
        $journal = $this->posting->existingJournal($bill);
        $reversal = null;

        if ($journal !== null) {
            $reversal = $this->reverseJournal->handle($journal, $by, $on, $memo, $pass);
        }

        // ⚠ Give the quantity back under a lock, the same reason the bill
        // took it under one: two people undoing and billing at once would
        // otherwise each read a stale counter.
        foreach ($bill->lines()->with('purchaseOrderLine')->get() as $line) {
            if ($line->purchase_order_line_id === null) {
                continue;
            }

            $poLine = PurchaseOrderLine::whereKey($line->purchase_order_line_id)
                ->lockForUpdate()
                ->firstOrFail();

            $restored = $this->scaled((string) $poLine->quantity_invoiced) - $this->scaled((string) $line->quantity);

            $poLine->update([
                'quantity_invoiced' => $this->unscaled(max(0, $restored)),
            ]);
        }

        // ⭐ Ruling P5: a carriage draft this bill raised and nobody has sent
        // on goes with it; nothing of it was posted.
        foreach (LandedCost::query()->where('supplier_bill_id', $bill->id)->get() as $landedCost) {
            if ($landedCost->isEditable()) {
                $landedCost->approvalRequest?->delete();
                $landedCost->delete();
            }
        }

        return $reversal;
    }

    private function assertReversible(SupplierBill $bill, bool $settlementsMove = false): void
    {
        if ($bill->isReversed()) {
            throw new RuntimeException("Bill {$bill->bill_number} has already been reversed.");
        }

        // ⚠⚠ 18 Oct 2026 (finding P3): a landed cost's bill has no journal of
        // its own (the landed cost's journal credited Accounts Payable), so
        // reversing it here would mark it reversed and leave the payable
        // standing. A posted landed cost is corrected as a landed cost.
        if ($bill->isLandedCostBill()) {
            throw new DomainException(
                "Bill {$bill->bill_number} records a posted landed cost. It cannot be reversed on its own; correct the landed cost instead, or record a supplier credit note."
            );
        }

        // ⚠ Paid or credited, either one. `paidTotal()` and `creditedTotal()`
        // are derived from the allocation tables rather than a stored column,
        // so this cannot drift out of step with what was actually settled.
        if (! $settlementsMove && $bill->paidTotal()->minor > 0) {
            throw new DomainException(
                "Bill {$bill->bill_number} has already been paid. Record a supplier credit note instead of reversing it."
            );
        }

        if (! $settlementsMove && $bill->creditedTotal()->minor > 0) {
            throw new DomainException(
                "Bill {$bill->bill_number} has already been credited. Reversing it now would unwind the credit note too."
            );
        }

        // ⭐ Ruling P5 (19 Oct 2026): carriage from this bill that has gone on
        // through the landed-cost workflow is its own document now, with its
        // own liability; it is corrected there first.
        $sentOn = LandedCost::query()->where('supplier_bill_id', $bill->id)->get()
            ->first(fn (LandedCost $landedCost) => ! $landedCost->isEditable());

        if ($sentOn !== null) {
            throw new DomainException("Bill {$bill->bill_number} raised landed cost {$sentOn->number}, which is ".($sentOn->status === LandedCostStatus::Posted ? 'posted' : 'sent for approval').'. Correct that landed cost first, then reverse the bill.');
        }

        // ⭐ Supplier discounts (ruling P3, 18 Oct 2026): its own document, reversed on its own.
        $discount = SupplierDiscount::query()->where('supplier_bill_id', $bill->id)->whereNull('reversed_at')->value('number');

        if ($discount !== null) {
            throw new DomainException("Bill {$bill->bill_number} carries discount {$discount}. Reverse the discount first; it puts the cost back on the batches.");
        }

        // ⭐ Supplier advances (ruling P2): an advance applied to it is reversed
        // on its own first, so the advance is open again for another bill.
        $application = SupplierAdvanceApplication::query()->where('supplier_bill_id', $bill->id)->whereNull('reversed_at')->value('number');

        if ($application !== null) {
            throw new DomainException("Bill {$bill->bill_number} has advance application {$application} against it. Reverse that application first; the advance becomes free again.");
        }

        // ⚠ A discount or set-off settled part of it too. Reversing would leave
        // that adjustment's debit to Accounts Payable with no bill behind it.
        if ($bill->adjustedTotal()->minor > 0) {
            throw new DomainException(
                "Bill {$bill->bill_number} has a discount or set-off against it. Remove that adjustment first, or record a supplier credit note instead."
            );
        }
    }

    private function scaled(string $quantity): int
    {
        return (int) round(((float) $quantity) * 10000);
    }

    private function unscaled(int $scaled): string
    {
        return number_format($scaled / 10000, 4, '.', '');
    }
}
