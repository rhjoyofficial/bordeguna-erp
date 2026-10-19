<?php

namespace App\Modules\Sales\Actions\Corrections;

use App\Models\User;
use App\Modules\Accounting\Actions\ReverseJournal;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Corrections\ClosedPeriodPass;
use App\Modules\Sales\Corrections\Checks\SalesLedgerCheck;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Models\CustomerReceiptAllocation;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * ⭐ Taking a customer receipt back (corrections wave 1, 20 Oct 2026): the
 * mirror of ReverseSupplierPayment's restore(), for the customer side.
 *
 *   undo()  ReverseJournal on the receipt's one journal (Dr 1140 customer /
 *           Cr the money account), on the date the correction rules give; then
 *           its allocations are RELEASED, kept on the receipt as
 *           `reversed_allocations`, so every invoice it paid owes again by
 *           exactly what it took
 *   mark()  the Reversible columns; the amount, the date and the account are
 *           never touched
 *
 * ⚠ Called only from inside a correction's transaction (the receipt corrector,
 * the sales invoice corrector's Void sale), never on its own: the policy, the
 * dates and the Owner's pass are decided there.
 */
class UndoCustomerReceipt
{
    public function __construct(private readonly ReverseJournal $reverseJournal) {}

    public function undo(CustomerReceipt $receipt, User $by, Carbon $on, string $memo, ?ClosedPeriodPass $pass = null): ?Journal
    {
        $journal = SalesLedgerCheck::journalOf($receipt);

        if ($journal === null && SalesLedgerCheck::expectsJournal($receipt)) {
            throw new LogicException("Receipt #{$receipt->id} has no single ledger entry to reverse.");
        }

        $reversal = $journal === null ? null : $this->reverseJournal->handle($journal->load('lines'), $by, $on, $memo, $pass);

        $allocations = CustomerReceiptAllocation::query()
            ->where('customer_receipt_id', $receipt->id)
            ->with('invoice:id,invoice_number')
            ->lockForUpdate()
            ->get();

        $receipt->forceFill([
            'reversed_allocations' => $allocations->map(fn (CustomerReceiptAllocation $a) => [
                'sales_invoice_id' => (int) $a->sales_invoice_id,
                'invoice_number' => $a->invoice?->invoice_number,
                'amount' => Money::ofTaka((string) $a->amount)->toTaka(),
            ])->values()->all(),
        ])->save();

        CustomerReceiptAllocation::query()->whereKey($allocations->modelKeys())->delete();

        return $reversal;
    }

    public function mark(CustomerReceipt $receipt, User $by, Carbon $on, string $reason, ?Journal $reversal): void
    {
        $receipt->forceFill([
            'reversed_on' => $on,
            'reversed_at' => now(),
            'reversed_by' => $by->id,
            'reversal_reason' => $reason,
            'reversal_journal_id' => $reversal?->id,
        ])->save();
    }
}
