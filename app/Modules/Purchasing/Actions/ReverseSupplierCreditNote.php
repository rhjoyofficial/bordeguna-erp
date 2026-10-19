<?php

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Accounting\Actions\ReverseJournal;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Corrections\ClosedPeriodPass;
use App\Modules\Purchasing\Models\SupplierCreditNote;
use App\Modules\Purchasing\Models\SupplierCreditNoteAllocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * PUR-003 (owner decision 14 Oct 2026): a supplier credit note reversed.
 *
 *   the journal   Dr GRNI (and Input VAT) / Cr Accounts Payable: the late
 *                 return's claim goes back to GRNI and the supplier is owed
 *                 the bill again
 *   the bills     their balance is restored: the credit's allocations are
 *                 released
 *   the return    may be credited again (PurchaseReturn::creditedTotal counts
 *                 live credit notes only)
 */
class ReverseSupplierCreditNote extends ReversePurchasingDocument
{
    public function __construct(ReverseJournal $reverseJournal, private readonly PostPurchaseJournals $posting)
    {
        parent::__construct($reverseJournal);
    }

    /**
     * @throws ValidationException
     */
    public function handle(SupplierCreditNote $creditNote, User $by, string $reason, bool $onOriginalDate = false, ?Carbon $on = null, ?ClosedPeriodPass $pass = null): SupplierCreditNote
    {
        return $this->reverseDocument($creditNote, $by, $reason, $onOriginalDate, $on, $pass);
    }

    protected function label(Model $document): string
    {
        return "Supplier credit note {$document->credit_note_number}";
    }

    protected function originalDate(Model $document): Carbon
    {
        return Carbon::parse($document->credit_date->toDateString());
    }

    protected function journalOf(Model $document): ?Journal
    {
        return $this->posting->existingJournal($document);
    }

    protected function restore(Model $document): array
    {
        $allocations = SupplierCreditNoteAllocation::query()
            ->where('supplier_credit_note_id', $document->id)
            ->with('bill:id,bill_number')
            ->orderBy('id')
            ->get();

        $released = $allocations->map(fn (SupplierCreditNoteAllocation $allocation) => [
            'supplier_bill_id' => $allocation->supplier_bill_id,
            'bill_number' => $allocation->bill?->bill_number,
            'amount' => Money::ofTaka((string) $allocation->amount)->toTaka(),
        ])->all();

        SupplierCreditNoteAllocation::query()->whereKey($allocations->modelKeys())->delete();

        return ['reversed_allocations' => $released];
    }
}
