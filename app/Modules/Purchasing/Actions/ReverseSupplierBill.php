<?php

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Accounting\Actions\ReverseJournal;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Purchasing\Models\SupplierBill;
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
        $on = Carbon::today();
        AccountingPeriod::assertOpenFor($on);

        return DB::transaction(function () use ($bill, $reversedBy, $note, $on) {
            // ⚠ Bug hunt 16 Oct 2026: the reversed, paid and credited checks ran
            // on the caller's copy before the transaction, so a payment saved a
            // moment earlier could land on a bill being reversed. Re-read under
            // a lock and checked here, inside the transaction that writes.
            $bill = SupplierBill::query()->whereKey($bill->id)->lockForUpdate()->firstOrFail();
            $this->assertReversible($bill);

            $journal = $this->posting->existingJournal($bill);

            if ($journal !== null) {
                $this->reverseJournal->handle(
                    $journal,
                    $reversedBy,
                    $on,
                    "Reversal of supplier bill {$bill->bill_number}",
                );
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

            $bill->update([
                'reversed_at' => $on,
                'reversed_by' => $reversedBy->id,
                'reversal_note' => $note,
            ]);

            return $bill->fresh(['lines', 'supplier']);
        });
    }

    private function assertReversible(SupplierBill $bill): void
    {
        if ($bill->isReversed()) {
            throw new RuntimeException("Bill {$bill->bill_number} has already been reversed.");
        }

        // ⚠ Paid or credited, either one. `paidTotal()` and `creditedTotal()`
        // are derived from the allocation tables rather than a stored column,
        // so this cannot drift out of step with what was actually settled.
        if ($bill->paidTotal()->minor > 0) {
            throw new DomainException(
                "Bill {$bill->bill_number} has already been paid. Record a supplier credit note instead of reversing it."
            );
        }

        if ($bill->creditedTotal()->minor > 0) {
            throw new DomainException(
                "Bill {$bill->bill_number} has already been credited. Reversing it now would unwind the credit note too."
            );
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
