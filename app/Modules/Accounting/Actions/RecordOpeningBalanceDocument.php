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

        if (SalesInvoice::query()->where('invoice_number', $number)->exists()) {
            throw ValidationException::withMessages(['reference' => "Opening invoice {$number} is already entered."]);
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

        if (SupplierBill::query()->where('supplier_id', $supplier->id)->where('bill_number', $number)->exists()) {
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
     * reversed and the document removed. Refused once anything has paid or
     * credited it, because that money would be left pointing at nothing.
     *
     * @throws ValidationException
     */
    public function remove(SalesInvoice|SupplierBill $document, User $by): void
    {
        if (! $document->is_opening) {
            throw ValidationException::withMessages(['document' => 'Only an opening balance can be removed here.']);
        }

        DB::transaction(function () use ($document, $by) {
            // ⚠ Re-read under a lock, the checks inside the transaction: two
            // removals at once both passed them and reversed the journal twice.
            $document = $document::query()->whereKey($document->getKey())->lockForUpdate()->first()
                ?? throw ValidationException::withMessages(['document' => 'That opening balance has already been removed.']);

            if ($document->allocations()->exists() || $document->creditAllocations()->exists()) {
                throw ValidationException::withMessages(['document' => 'Money has already been received or paid against it. Reverse that first.']);
            }

            $journal = Journal::query()
                ->where('source_type', $document->getMorphClass())
                ->where('source_id', $document->getKey())
                ->whereNull('reverses_journal_id')
                ->first();

            if ($journal !== null) {
                $this->reverseJournal->handle($journal, $by, null, 'Opening balance removed: entered by mistake');
            }

            $document->delete();
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
