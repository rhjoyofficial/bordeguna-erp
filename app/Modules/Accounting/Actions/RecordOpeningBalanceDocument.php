<?php

namespace App\Modules\Accounting\Actions;

use App\Models\User;
use App\Modules\Accounting\Enums\JournalType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\CorrectionLog;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Money owed to or by the business at cutover, one document per genuinely open
 * invoice or bill (finance decision A4/A5, 29 Sep 2026).
 *
 *   opening invoice   Dr 1140 Accounts Receivable + customer / Cr 3900
 *   opening bill      Dr 3900 / Cr 2110 Accounts Payable + supplier
 *
 * ⚠⚠ DATED ON THE ORIGINAL DOCUMENT DATE, not the cutover date: the ageing, the
 * statements and the reconciliation tie documents to the ledger by date, and
 * the ageing is the whole reason these are documents rather than one journal.
 *
 * ⚠ Only the REMAINING amount is entered (an invoice of 30,000 with 12,000
 * already paid before cutover opens at 18,000). Nothing of the original sale,
 * purchase, stock or VAT is reproduced.
 */
class RecordOpeningBalanceDocument
{
    public function __construct(
        private readonly PostJournal $postJournal,
        private readonly ReverseJournal $reverseJournal,
    ) {}

    /**
     * @throws ValidationException
     */
    public function invoice(Customer $customer, string $reference, mixed $date, mixed $dueDate, string $amount, User $by): SalesInvoice
    {
        [$on, $due, $money] = $this->validated($date, $dueDate, $amount);
        // ⚠ "OB-" keeps the old number clear of the INV-{year}- sequence, which
        // reads the highest existing number to pick the next one.
        $number = 'OB-'.trim($reference);

        if (($existing = SalesInvoice::query()->where('invoice_number', $number)->first()) !== null) {
            // ⭐ B13: a reversed one keeps its number for ever (never reused).
            throw ValidationException::withMessages(['reference' => $existing->isReversed()
                ? "Opening invoice {$number} was entered and reversed; its number stays with it. Enter the right one with another reference, for example ".trim($reference).'-2.'
                : "Opening invoice {$number} is already entered."]);
        }

        return DB::transaction(function () use ($customer, $number, $on, $due, $money, $by) {
            $invoice = SalesInvoice::create([
                'business_id' => $customer->business_id,
                'customer_id' => $customer->id,
                'invoice_number' => $number,
                'invoice_date' => $on,
                'due_date' => $due,
                'subtotal' => $money->toTaka(),
                'total' => $money->toTaka(),
                'is_opening' => true,
                'note' => 'Opening balance at cutover',
                'created_by' => $by->id,
            ]);

            $this->postJournal->handle(new JournalDraft(
                date: $on,
                lines: [
                    JournalLineDraft::debit(AccountMapping::resolve('sales.accounts_receivable', $on)->id, $money, "Opening {$number}", $customer),
                    JournalLineDraft::credit(Account::bySlug('opening_balance_equity')->id, $money, "Opening {$number}"),
                ],
                memo: "Opening receivable {$number} - {$customer->name}",
                source: $invoice,
                type: JournalType::OpeningBalance,
            ), $by);

            return $invoice;
        });
    }

    /**
     * @throws ValidationException
     */
    public function bill(Supplier $supplier, string $billNumber, mixed $date, mixed $dueDate, string $amount, User $by): SupplierBill
    {
        [$on, $due, $money] = $this->validated($date, $dueDate, $amount);
        $number = trim($billNumber);

        // PUR-004 (14 Oct 2026): only a live bill holds its number.
        if (SupplierBill::query()->where('supplier_id', $supplier->id)->where('bill_number', $number)->whereNull('reversed_at')->exists()) {
            throw ValidationException::withMessages(['reference' => "{$supplier->name} already has a bill {$number}."]);
        }

        return DB::transaction(function () use ($supplier, $number, $on, $due, $money, $by) {
            $bill = SupplierBill::create([
                'business_id' => $supplier->business_id,
                'supplier_id' => $supplier->id,
                'bill_number' => $number,
                'bill_date' => $on,
                'due_date' => $due,
                'total' => $money->toTaka(),
                'is_opening' => true,
                'note' => 'Opening balance at cutover',
                'created_by' => $by->id,
            ]);

            $this->postJournal->handle(new JournalDraft(
                date: $on,
                lines: [
                    JournalLineDraft::debit(Account::bySlug('opening_balance_equity')->id, $money, "Opening bill {$number}"),
                    JournalLineDraft::credit(AccountMapping::resolve('purchasing.accounts_payable', $on)->id, $money, "Opening bill {$number}", $supplier),
                ],
                memo: "Opening payable {$number} - {$supplier->name}",
                source: $bill,
                type: JournalType::OpeningBalance,
            ), $by);

            return $bill;
        });
    }

    /**
     * Takes back an opening document entered by mistake: its journal is
     * reversed and the document is KEPT, marked Reversed (B13, 24 Oct 2026;
     * FINAL-PLAN 3.1 "Party adjustment, opening receivable or payable: Reverse
     * with reason and date choice. The document is kept, marked Reversed").
     * Before this the row was hard deleted and the ageing lost it. Refused once
     * anything has paid or credited it, because that money would be left
     * pointing at nothing.
     *
     * The reversal goes on the document's own date while its month is open,
     * or today when asked (or when that month is closed: the boundary is never
     * crossed from here).
     *
     * @throws ValidationException
     */
    public function remove(SalesInvoice|SupplierBill $document, User $by, ?ActionReason $reason = null, bool $today = false): void
    {
        if (! $document->is_opening) {
            throw ValidationException::withMessages(['document' => 'Only an opening balance can be removed here.']);
        }

        $why = $reason?->display() ?? 'Entered by mistake';

        DB::transaction(function () use ($document, $by, $why, $today) {
            // ⚠ Re-read under a lock, the checks inside the transaction: two
            // removals at once both passed them and reversed the journal twice.
            $document = $document::query()->whereKey($document->getKey())->lockForUpdate()->first()
                ?? throw ValidationException::withMessages(['document' => 'That opening balance does not exist.']);

            if ($document->isReversed()) {
                throw ValidationException::withMessages(['document' => 'That opening balance has already been removed: it stays, marked reversed.']);
            }

            if ($document->allocations()->exists() || $document->creditAllocations()->exists()) {
                throw ValidationException::withMessages(['document' => 'Money has already been received or paid against it. Reverse that first.']);
            }

            // ⚠ A write-off, discount or set-off settled part of it too; its
            // allocation row would block the delete with a raw database error.
            if ($document->adjustmentAllocations()->exists()) {
                throw ValidationException::withMessages(['document' => 'A write-off, discount or set-off has been recorded against it. Remove that adjustment first.']);
            }

            $journal = Journal::query()
                ->where('source_type', $document->getMorphClass())
                ->where('source_id', $document->getKey())
                ->whereNull('reverses_journal_id')
                ->first();

            $own = Carbon::parse($document instanceof SalesInvoice ? $document->invoice_date : $document->bill_date)->startOfDay();
            $on = $today || AccountingPeriod::isClosedOn($own) ? Carbon::today() : $own;
            $reversal = $journal === null ? null : $this->reverseJournal->handle($journal, $by, $on, "Opening balance reversed: {$why}");

            // A bill keeps its reversal in its own older columns (PUR-003); an
            // invoice in the shared Reversible ones.
            $document->forceFill($document instanceof SupplierBill
                ? ['reversed_at' => now(), 'reversed_by' => $by->id, 'reversal_note' => "Opening balance reversed on {$on->toDateString()}: {$why}"]
                : [
                    'reversed_on' => $reversal?->journal_date ?? $on,
                    'reversed_at' => now(),
                    'reversed_by' => $by->id,
                    'reversal_reason' => $why,
                    'reversal_journal_id' => $reversal?->id,
                ])->save();

            CorrectionLog::event('reversed', $by, $document, ['number' => $document instanceof SalesInvoice ? $document->invoice_number : $document->bill_number, 'reason' => $why, 'date' => $on->toDateString()]);
        });
    }

    /**
     * @return array{0: Carbon, 1: ?Carbon, 2: Money}
     */
    private function validated(mixed $date, mixed $dueDate, string $amount): array
    {
        $on = Carbon::parse($date)->startOfDay();
        $due = blank($dueDate) ? null : Carbon::parse($dueDate)->startOfDay();
        $money = Money::ofTaka($amount);

        if ($money->minor <= 0) {
            throw ValidationException::withMessages(['amount' => 'Enter the amount still owed.']);
        }

        if ($on->isFuture()) {
            throw ValidationException::withMessages(['date' => 'An opening balance is from before today.']);
        }

        if ($due !== null && $due->lt($on)) {
            throw ValidationException::withMessages(['due_date' => 'The due date cannot be before the document date.']);
        }

        AccountingPeriod::assertOpenFor($on);

        return [$on, $due, $money];
    }
}
