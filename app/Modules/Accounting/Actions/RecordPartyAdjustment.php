<?php

namespace App\Modules\Accounting\Actions;

use App\Models\User;
use App\Modules\Accounting\Corrections\PartyAdjustmentCorrector;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\PartyAdjustment;
use App\Modules\Accounting\Models\SetOff;
use App\Modules\Accounting\Support\JournalDraft;
use App\Modules\Accounting\Support\JournalLineDraft;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Corrections\ClosedPeriodPass;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierAdvanceApplication;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierDiscount;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A write-off, discount or set-off (finance decision C3, the journal half).
 *
 * ⚠⚠ ALWAYS AGAINST NAMED INVOICES AND BILLS, never a floating amount: that is
 * what keeps the ageing, the statements and the reconciliation agreeing with
 * the ledger. Each allocation is re-read under a lock and may not exceed what
 * the document still owes.
 */
class RecordPartyAdjustment
{
    public function __construct(
        private readonly PostJournal $postJournal,
        private readonly ReverseJournal $reverseJournal,
    ) {}

    /**
     * @param  array<int, string>  $invoices  sales_invoice_id => amount (taka)
     * @param  array<int, string>  $bills  supplier_bill_id => amount (taka)
     * @param  Model|null  $source  ⭐ 18 Oct 2026: the document the journal belongs
     *                              to when it is not the adjustment itself (an
     *                              approved SetOff), so the corrections process
     *                              finds exactly one ledger entry on it.
     *
     * @throws ValidationException
     */
    /**
     * ⭐ 24 Oct 2026: `$pass` is the Owner's one-document closed-month
     * exception, handed down by a sales price credit dated in a closed month
     * (the corrections engine). Null for every other caller.
     */
    public function handle(string $kind, mixed $date, ?Customer $customer, ?Supplier $supplier, array $invoices, array $bills, string $reason, User $by, ?Model $source = null, ?ClosedPeriodPass $pass = null): PartyAdjustment
    {
        if (! array_key_exists($kind, PartyAdjustment::KINDS)) {
            throw ValidationException::withMessages(['kind' => 'Choose what kind of adjustment this is.']);
        }

        // ⭐ Supplier advances (ruling P2): applying an advance is its own
        // document (Purchases > Bills, Apply advances), with its own journal.
        if ($kind === 'advance_applied') {
            throw ValidationException::withMessages(['kind' => 'Apply a supplier advance from the bill or the supplier page.']);
        }

        // ⭐ Supplier discounts (ruling P3): a trade or settlement discount is its
        // own SD- document, whose journal also takes cost off the goods.
        if (in_array($kind, ['supplier_trade_discount', 'supplier_settlement_discount'], true)) {
            throw ValidationException::withMessages(['kind' => 'Record a supplier discount on the bill or the payment.']);
        }

        $on = Carbon::parse($date)->startOfDay();

        if ($on->isFuture()) {
            throw ValidationException::withMessages(['adjusted_on' => 'An adjustment cannot be dated in the future.']);
        }

        AccountingPeriod::assertOpenFor($on, $pass);

        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Say why: the reason is part of the record.']);
        }

        $needsCustomer = $kind !== 'supplier_discount';
        $needsSupplier = in_array($kind, ['supplier_discount', 'set_off'], true);

        if ($needsCustomer && $customer === null) {
            throw ValidationException::withMessages(['customer_id' => 'Choose the customer.']);
        }

        if ($needsSupplier && $supplier === null) {
            throw ValidationException::withMessages(['supplier_id' => 'Choose the supplier.']);
        }

        $invoices = $needsCustomer ? array_filter($invoices, fn ($a) => Money::ofTaka((string) $a)->minor > 0) : [];
        $bills = $needsSupplier ? array_filter($bills, fn ($a) => Money::ofTaka((string) $a)->minor > 0) : [];

        return DB::transaction(function () use ($kind, $on, $customer, $supplier, $invoices, $bills, $reason, $by, $needsCustomer, $needsSupplier, $source, $pass) {
            $customerSide = Money::zero();
            $supplierSide = Money::zero();

            foreach ($invoices as $id => $amount) {
                $invoice = SalesInvoice::query()->whereKey($id)->lockForUpdate()->first();

                if ($invoice === null || $invoice->customer_id !== $customer->id) {
                    throw ValidationException::withMessages(['invoices' => 'An invoice chosen does not belong to this customer.']);
                }

                $money = Money::ofTaka((string) $amount);

                if ($money->minor > $invoice->outstanding()->minor) {
                    throw ValidationException::withMessages(['invoices' => "Invoice {$invoice->invoice_number} has only {$invoice->outstanding()->format()} outstanding."]);
                }

                $customerSide = $customerSide->plus($money);
            }

            foreach ($bills as $id => $amount) {
                $bill = SupplierBill::query()->whereKey($id)->lockForUpdate()->first();

                if ($bill === null || $bill->supplier_id !== $supplier->id) {
                    throw ValidationException::withMessages(['bills' => 'A bill chosen does not belong to this supplier.']);
                }

                $money = Money::ofTaka((string) $amount);

                if ($money->minor > $bill->outstanding()->minor) {
                    throw ValidationException::withMessages(['bills' => "Bill {$bill->bill_number} has only {$bill->outstanding()->format()} outstanding."]);
                }

                // ⚠⚠ 18 Oct 2026 (ruling P3, test 22): a bill whose goods already
                // took a trade or settlement discount has had its discount; a
                // second one to 4950 would count it twice.
                if ($kind === 'supplier_discount' && ($taken = SupplierDiscount::query()->where('supplier_bill_id', $bill->id)->whereNull('reversed_at')->value('number')) !== null) {
                    throw ValidationException::withMessages(['bills' => "Bill {$bill->bill_number} already carries discount {$taken} on its goods. Reverse that discount first if this is the same one."]);
                }

                $supplierSide = $supplierSide->plus($money);
            }

            if (($needsCustomer && $customerSide->isZero()) || ($needsSupplier && $supplierSide->isZero())) {
                throw ValidationException::withMessages(['invoices' => 'Say how much of which invoice or bill this settles.']);
            }

            if ($kind === 'set_off' && $customerSide->minor !== $supplierSide->minor) {
                throw ValidationException::withMessages([
                    'bills' => "A set-off moves the same amount on both sides: the invoices come to {$customerSide->format()}, the bills to {$supplierSide->format()}.",
                ]);
            }

            $total = $needsCustomer ? $customerSide : $supplierSide;

            $adjustment = PartyAdjustment::create([
                'business_id' => $customer?->business_id ?? $supplier->business_id,
                'kind' => $kind,
                'adjusted_on' => $on,
                'customer_id' => $needsCustomer ? $customer->id : null,
                'supplier_id' => $needsSupplier ? $supplier->id : null,
                'amount' => $total->toTaka(),
                'reason' => trim($reason),
                'created_by' => $by->id,
            ]);

            foreach ($invoices as $id => $amount) {
                $adjustment->allocations()->create(['business_id' => $adjustment->business_id, 'sales_invoice_id' => $id, 'amount' => Money::ofTaka((string) $amount)->toTaka()]);
            }

            foreach ($bills as $id => $amount) {
                $adjustment->allocations()->create(['business_id' => $adjustment->business_id, 'supplier_bill_id' => $id, 'amount' => Money::ofTaka((string) $amount)->toTaka()]);
            }

            $ar = fn () => AccountMapping::resolve('sales.accounts_receivable', $on)->id;
            $ap = fn () => AccountMapping::resolve('purchasing.accounts_payable', $on)->id;
            $memo = "{$adjustment->label()}: {$adjustment->reason}";

            $lines = match ($kind) {
                'customer_write_off' => [
                    JournalLineDraft::debit(Account::bySlug('bad_debts')->id, $total, $memo),
                    JournalLineDraft::credit($ar(), $total, $memo, $customer),
                ],
                'customer_discount' => [
                    JournalLineDraft::debit(Account::bySlug('discounts_allowed')->id, $total, $memo),
                    JournalLineDraft::credit($ar(), $total, $memo, $customer),
                ],
                // ⭐ Slice 8: the rounding head when it is mapped, else a
                // discount allowed, as LedgerRounding would choose.
                'rounding' => [
                    JournalLineDraft::debit((AccountMapping::forKeyOn('accounting.rounding_difference', $on)?->account_id) ?? Account::bySlug('discounts_allowed')->id, $total, $memo),
                    JournalLineDraft::credit($ar(), $total, $memo, $customer),
                ],
                'supplier_discount' => [
                    JournalLineDraft::debit($ap(), $total, $memo, $supplier),
                    JournalLineDraft::credit(Account::bySlug('discounts_received')->id, $total, $memo),
                ],
                'set_off' => [
                    JournalLineDraft::debit($ap(), $total, $memo, $supplier),
                    JournalLineDraft::credit($ar(), $total, $memo, $customer),
                ],
                // ⭐ Corrections wave 1: a lower price on goods kept, contra
                // revenue on the face of the P&L like a return (C-SR-5).
                'customer_credit_note' => [
                    JournalLineDraft::debit(AccountMapping::resolve('sales.sales_returns', $on)->id, $total, $memo),
                    JournalLineDraft::credit($ar(), $total, $memo, $customer),
                ],
            };

            $this->postJournal->handle(new JournalDraft(date: $on, lines: $lines, memo: $memo, source: $source ?? $adjustment), $by, null, $pass);

            return $adjustment->fresh('allocations');
        });
    }

    /**
     * Takes back an adjustment entered by mistake: its journal is reversed today
     * and the document is KEPT, marked Reversed (corrections wave 2). The
     * Adjustments screen now reverses through the corrections process, with a
     * reason and the date choice; this older path stays for its callers.
     */
    public function remove(PartyAdjustment $adjustment, User $by): void
    {
        // ⚠⚠ 18 Oct 2026: the adjustment an approved set-off posted is undone
        // only through the corrections process, which reverses the set-off's
        // own journal and marks the document. Removing it here would reopen
        // the bill and the invoice and leave the journal standing.
        $setOff = SetOff::query()->where('party_adjustment_id', $adjustment->getKey())->value('number');

        if ($setOff !== null) {
            throw ValidationException::withMessages(['adjustment' => "This adjustment belongs to set-off {$setOff}. Reverse the set-off instead (Set-offs > Reverse)."]);
        }

        // ⭐ Supplier advances (ruling P2): the same for an advance application.
        $application = SupplierAdvanceApplication::query()->where('party_adjustment_id', $adjustment->getKey())->value('number');

        if ($application !== null) {
            throw ValidationException::withMessages(['adjustment' => "This adjustment belongs to advance application {$application}. Reverse that application instead (on the bill)."]);
        }

        // ⚠ 18 Oct 2026 (ruling P3): likewise a supplier discount's, whose
        // journal also took cost off the batches.
        $discount = SupplierDiscount::query()->where('party_adjustment_id', $adjustment->getKey())->value('number');

        if ($discount !== null) {
            throw ValidationException::withMessages(['adjustment' => "This adjustment belongs to discount {$discount}. Reverse the discount on its bill instead."]);
        }

        // ⭐ Corrections wave 1: a price credit note's, which has its own number.
        $creditNote = DB::table('sales_credit_notes')->where('party_adjustment_id', $adjustment->getKey())->value('credit_note_number');

        if ($creditNote !== null) {
            throw ValidationException::withMessages(['adjustment' => "This adjustment belongs to credit note {$creditNote}. It stays: a credit note is never deleted."]);
        }

        // ⭐ 20 Oct 2026 (party ledgers): one a journal voucher made is the
        // voucher's journal; the voucher is reversed, and this goes with it.
        if ($adjustment->voucher_id !== null) {
            $number = DB::table('vouchers')->where('id', $adjustment->voucher_id)->value('voucher_number');

            throw ValidationException::withMessages(['adjustment' => "This adjustment was made by journal voucher {$number}. Reverse the voucher instead."]);
        }

        DB::transaction(function () use ($adjustment, $by) {
            // ⚠ Re-read under a lock: two removals at once must not both pass.
            // The second finds it already reversed.
            $adjustment = PartyAdjustment::query()->whereKey($adjustment->getKey())->lockForUpdate()->first()
                ?? throw ValidationException::withMessages(['adjustment' => 'That adjustment does not exist.']);

            if ($adjustment->isReversed()) {
                throw ValidationException::withMessages(['adjustment' => 'That adjustment has already been removed: it stays, marked reversed.']);
            }

            $journal = Journal::query()
                ->where('source_type', $adjustment->getMorphClass())->where('source_id', $adjustment->getKey())
                ->whereNull('reverses_journal_id')->first();

            $reversal = $journal === null ? null : $this->reverseJournal->handle($journal, $by, null, "Adjustment removed: {$adjustment->label()}");

            // ⭐ Corrections wave 2 (24 Oct 2026): KEPT and marked, never
            // deleted. Its allocations are released (kept on it), so the
            // invoices and bills owe again exactly as before.
            $adjustment->forceFill([
                'reversed_allocations' => PartyAdjustmentCorrector::releaseAllocations($adjustment),
                'reversed_on' => $reversal?->journal_date ?? now()->startOfDay(),
                'reversed_at' => now(),
                'reversed_by' => $by->id,
                'reversal_reason' => 'Removed: entered by mistake',
                'reversal_journal_id' => $reversal?->id,
            ])->save();
        });
    }
}
