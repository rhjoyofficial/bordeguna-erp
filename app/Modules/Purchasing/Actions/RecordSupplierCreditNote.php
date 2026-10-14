<?php

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Purchasing\Models\PurchaseReturn;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierCreditNote;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records the supplier's credit note for goods returned after being billed.
 *
 * ⚠⚠ THE CLAIM A LATE RETURN CREATES HAD NO SETTLEMENT PATH. Returning goods
 * after the bill leaves GRNI in debit - the supplier owes us - and the
 * supplier's payable balance overstates what will actually be paid, forever.
 * The credit note is the document that resolves it: Dr AP / Cr GRNI, allocated
 * against SPECIFIC bills exactly as a payment is.
 *
 * ⚠ Return-linked only (D-SCN-1) and capped by the return's billed value
 * (C-SCN-2). Crediting more than was ever returned is how a payable goes
 * quietly negative.
 */
class RecordSupplierCreditNote
{
    public function __construct(private readonly PostPurchaseJournals $posting) {}

    /**
     * @param  array<int, array{bill: SupplierBill, amount: string}>  $allocations
     *
     * @throws ValidationException
     */
    public function handle(
        Supplier $supplier,
        PurchaseReturn $purchaseReturn,
        string $creditNoteNumber,
        mixed $creditDate,
        array $allocations,
        User $createdBy,
        ?string $note = null,
        // ⭐ VAT (plan D6/D7): how much of the credit is VAT the supplier is
        // giving back. It is INSIDE the amounts allocated to the bills, since a
        // bill's outstanding balance includes its VAT. It comes back out of
        // Input VAT Receivable, and only the rest relieves GRNI.
        string $taxAmount = '0',
    ): SupplierCreditNote {
        $date = Carbon::parse($creditDate)->startOfDay();

        AccountingPeriod::assertOpenFor($date);

        // ⚠ PUR-005 (audit 14 Oct 2026): the same credit-note number from the
        // same supplier twice reached the unique index and gave a blank 500,
        // losing the form. Checked first, as bills are, so it is a sentence
        // under the number; the index still stands behind it (see below).
        $this->assertNumberFree($supplier, $creditNoteNumber);

        if ($purchaseReturn->purchaseOrder?->supplier_id !== $supplier->id) {
            throw ValidationException::withMessages([
                'purchase_return_id' => 'That return was not made to this supplier.',
            ]);
        }

        $allocations = array_values(array_filter(
            $allocations,
            fn (array $entry) => Money::ofTaka($entry['amount'])->minor > 0,
        ));

        if ($allocations === []) {
            throw ValidationException::withMessages([
                'allocations' => 'Say which bills this credit reduces, and by how much each.',
            ]);
        }

        $tax = Money::ofTaka($taxAmount === '' ? '0' : $taxAmount);

        if ($tax->minor < 0) {
            throw ValidationException::withMessages(['tax_amount' => 'The VAT on a credit note cannot be negative.']);
        }

        return DB::transaction(function () use ($supplier, $purchaseReturn, $creditNoteNumber, $date, $allocations, $createdBy, $note, $tax) {
            // ⚠ Re-read under a lock: two clerks recording credits against the
            // same return at once is how a return gets credited twice, and
            // `lockForUpdate` is a no-op on SQLite, so the RE-READ is the part
            // that makes this correct. Fourth application of the I3 lesson.
            $return = PurchaseReturn::query()->whereKey($purchaseReturn->id)->lockForUpdate()->firstOrFail();

            $total = Money::zero();

            foreach ($allocations as $index => $entry) {
                /** @var SupplierBill $bill */
                $bill = SupplierBill::query()->whereKey($entry['bill']->id)->lockForUpdate()->firstOrFail();

                if ($bill->supplier_id !== $supplier->id) {
                    // ⚠ C-PUR-E2. `allocations` and not `allocations.N.bill`:
                    // both screens render the top-level key and neither renders
                    // a per-line bill key, so the old one was never displayed.
                    // The message already names the bill, so nothing is lost.
                    throw ValidationException::withMessages([
                        'allocations' => "Bill {$bill->bill_number} belongs to a different supplier.",
                    ]);
                }

                $amount = Money::ofTaka($entry['amount']);
                $outstanding = $bill->outstanding();

                // ⚠ A credit larger than what the bill still owes would make
                // the bill read as overpaid. Refused, exactly as an overpayment
                // is - if the supplier's credit exceeds their open bills, the
                // rest belongs to a future bill and is recorded when it exists.
                if ($amount->minor > $outstanding->minor) {
                    throw ValidationException::withMessages([
                        "allocations.{$index}.amount" => "Bill {$bill->bill_number} has only {$outstanding->format()} outstanding.",
                    ]);
                }

                $total = $total->plus($amount);
            }

            // ⚠⚠ THE CAP (C-SCN-2): the return's billed value, less credits
            // already recorded against it. The figure is the one the return's
            // own posting debited GRNI with, so a fully credited return brings
            // GRNI to exactly zero rather than stranding paisa.
            if ($tax->minor > $total->minor) {
                throw ValidationException::withMessages(['tax_amount' => 'The VAT cannot be more than the credit note itself.']);
            }

            $creditable = $return->invoiceableValue()->plus($return->creditedTotal()->negated());

            // ⚠ The return is valued at COST, which holds no claimable VAT, so
            // it is the goods part of the credit that is measured against it.
            if ($total->minor - $tax->minor > $creditable->minor) {
                throw ValidationException::withMessages([
                    'allocations' => "Return #{$return->id} has only {$creditable->format()} left to credit.",
                ]);
            }

            try {
                $creditNote = SupplierCreditNote::create([
                    'supplier_id' => $supplier->id,
                    'purchase_return_id' => $return->id,
                    'credit_note_number' => $creditNoteNumber,
                    'credit_date' => $date,
                    'total' => $total->toTaka(),
                    'tax_total' => $tax->toTaka(),
                    'note' => $note,
                    'created_by' => $createdBy->id,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Two people saving the same number in the same second: the
                // check above passed for both, the index refused the second.
                $this->assertNumberFree($supplier, $creditNoteNumber);

                throw ValidationException::withMessages([
                    'credit_note_number' => "Credit note {$creditNoteNumber} has already been recorded for {$supplier->name}.",
                ]);
            }

            foreach ($allocations as $entry) {
                $creditNote->allocations()->create([
                    'business_id' => $creditNote->business_id,
                    'supplier_bill_id' => $entry['bill']->id,
                    'amount' => Money::ofTaka($entry['amount'])->toTaka(),
                ]);
            }

            // Posted in the same transaction as the document, so a credit the
            // ledger cannot see is not a state this can reach.
            $this->posting->forCreditNote($creditNote->fresh(['allocations', 'supplier']), $createdBy);

            return $creditNote->fresh(['allocations', 'supplier']);
        });
    }

    /**
     * PUR-005: the supplier's credit-note number, refused as a sentence when
     * it was recorded before.
     */
    private function assertNumberFree(Supplier $supplier, string $creditNoteNumber): void
    {
        if (SupplierCreditNote::query()->where('supplier_id', $supplier->id)->where('credit_note_number', $creditNoteNumber)->exists()) {
            throw ValidationException::withMessages([
                'credit_note_number' => "Credit note {$creditNoteNumber} has already been recorded for {$supplier->name}.",
            ]);
        }
    }
}
