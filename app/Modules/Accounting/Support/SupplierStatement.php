<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Models\PartyAdjustment;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierBill;
use App\Modules\Purchasing\Models\SupplierCreditNote;
use App\Modules\Purchasing\Models\SupplierPayment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One supplier's account for a period: every bill, credit note and payment, in
 * order, with a running balance and what the business owes at the end.
 *
 * The mirror of `CustomerStatement`, with the same C-CST-1 stance: BUILT FROM
 * DOCUMENTS, CHECKED BY THE LEDGER. The lines carry the bill and payment numbers
 * the supplier recognises; the supplier's party-tagged share of Accounts Payable
 * independently computes the opening and closing, and the screen says whether
 * the two agree.
 *
 * ⚠ SIGN: `amount` is signed the way the business reads its debt, so POSITIVE
 * means we owe more (a bill) and NEGATIVE means we owe less (a payment, a credit
 * note). The ledger stores payables as credits (negative), so the check figure
 * is negated before it is compared. A "credit" side on the balance therefore
 * means the SUPPLIER owes US (an over-payment or advance).
 *
 * ⚠ Allocations do not appear, as on the customer statement: a payment reduces
 * what we owe whether or not it is allocated to a bill.
 * ⚠ Every window comparison is a DATE-STRING comparison (C-CST-5).
 */
final class SupplierStatement
{
    /**
     * @param  Collection<int, CustomerStatementLine>  $lines
     */
    private function __construct(
        public readonly Supplier $supplier,
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly Money $opening,
        public readonly Money $charges,
        public readonly Money $settlements,
        public readonly Collection $lines,
        private readonly ?Money $ledgerOpening,
        private readonly ?Money $ledgerClosing,
    ) {}

    public static function for(Supplier $supplier, Carbon $from, Carbon $to): self
    {
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();

        $movements = collect()
            ->concat(SupplierBill::query()
                ->where('supplier_id', $supplier->id)
                ->whereDate('bill_date', '<=', $toDate)
                ->get()
                ->map(fn (SupplierBill $bill) => [
                    'date' => Carbon::parse($bill->bill_date->toDateString()),
                    'kind' => 'bill',
                    'rank' => 0,
                    'id' => $bill->id,
                    'label' => "Bill {$bill->bill_number}",
                    'reference' => null,
                    'amount' => Money::ofTaka((string) $bill->total),
                ]))
            ->concat(SupplierCreditNote::query()
                ->where('supplier_id', $supplier->id)
                ->whereDate('credit_date', '<=', $toDate)
                ->get()
                ->map(fn (SupplierCreditNote $credit) => [
                    'date' => Carbon::parse($credit->credit_date->toDateString()),
                    'kind' => 'credit_note',
                    'rank' => 1,
                    'id' => $credit->id,
                    'label' => "Credit note {$credit->credit_note_number}",
                    'reference' => null,
                    'amount' => Money::ofTaka((string) $credit->total)->negated(),
                ]))
            ->concat(SupplierPayment::query()
                ->where('supplier_id', $supplier->id)
                ->whereDate('paid_on', '<=', $toDate)
                ->get()
                ->map(fn (SupplierPayment $payment) => [
                    'date' => Carbon::parse($payment->paid_on->toDateString()),
                    'kind' => 'payment',
                    'rank' => 2,
                    'id' => $payment->id,
                    'label' => 'Payment made',
                    'reference' => $payment->reference,
                    'amount' => Money::ofTaka((string) $payment->amount)->negated(),
                ]))
            // ⭐ PUR-003 (14 Oct 2026): a reversed bill, credit note or payment
            // stays on the statement on its own date, and its reversal follows
            // on the day the mirror journal is dated, so the running balance
            // agrees with the supplier's share of Accounts Payable on every
            // day. (A reversed bill used to stay with no reversal line, which
            // left the statement and the ledger apart by its total.)
            ->concat(SupplierBill::query()
                ->where('supplier_id', $supplier->id)
                ->whereNotNull('reversed_at')
                ->whereDate('reversed_at', '<=', $toDate)
                ->get()
                ->map(fn (SupplierBill $bill) => [
                    'date' => Carbon::parse($bill->reversed_at->toDateString()),
                    'kind' => 'bill_reversed',
                    'rank' => 4,
                    'id' => $bill->id,
                    'label' => "Bill {$bill->bill_number} reversed",
                    'reference' => $bill->reversal_note,
                    'amount' => Money::ofTaka((string) $bill->total)->negated(),
                ]))
            ->concat(SupplierCreditNote::query()
                ->where('supplier_id', $supplier->id)
                ->whereNotNull('reversed_on')
                ->whereDate('reversed_on', '<=', $toDate)
                ->get()
                ->map(fn (SupplierCreditNote $credit) => [
                    'date' => Carbon::parse($credit->reversed_on->toDateString()),
                    'kind' => 'credit_note_reversed',
                    'rank' => 5,
                    'id' => $credit->id,
                    'label' => "Credit note {$credit->credit_note_number} reversed",
                    'reference' => $credit->reversal_reason,
                    'amount' => Money::ofTaka((string) $credit->total),
                ]))
            ->concat(SupplierPayment::query()
                ->where('supplier_id', $supplier->id)
                ->whereNotNull('reversed_on')
                ->whereDate('reversed_on', '<=', $toDate)
                ->get()
                ->map(fn (SupplierPayment $payment) => [
                    'date' => Carbon::parse($payment->reversed_on->toDateString()),
                    'kind' => 'payment_reversed',
                    'rank' => 6,
                    'id' => $payment->id,
                    'label' => 'Payment reversed',
                    'reference' => $payment->reversal_reason,
                    'amount' => Money::ofTaka((string) $payment->amount),
                ]))
            ->concat(PartyAdjustment::query()
                ->where('supplier_id', $supplier->id)
                ->whereDate('adjusted_on', '<=', $toDate)
                ->get()
                ->map(fn (PartyAdjustment $adjustment) => [
                    'date' => Carbon::parse($adjustment->adjusted_on->toDateString()),
                    'kind' => 'adjustment',
                    'rank' => 3,
                    'id' => $adjustment->id,
                    'label' => $adjustment->label(),
                    'reference' => $adjustment->reason,
                    'amount' => $adjustment->money()->negated(),
                ]))
            ->sortBy([['date', 'asc'], ['rank', 'asc'], ['id', 'asc']])
            ->values();

        [$before, $inWindow] = $movements->partition(
            fn (array $movement) => $movement['date']->toDateString() < $fromDate
        );

        $opening = Money::sum($before->map(fn (array $movement) => $movement['amount']));

        $balance = $opening;
        $lines = $inWindow->values()->map(function (array $movement) use (&$balance) {
            $balance = $balance->plus($movement['amount']);

            return new CustomerStatementLine(
                date: $movement['date'],
                kind: $movement['kind'],
                label: $movement['label'],
                reference: $movement['reference'],
                amount: $movement['amount'],
                balance: $balance,
            );
        });

        $charges = Money::sum($inWindow->map(fn (array $movement) => $movement['amount'])
            ->filter(fn (Money $amount) => $amount->isDebit()));
        $settlements = Money::sum($inWindow->map(fn (array $movement) => $movement['amount'])
            ->filter(fn (Money $amount) => $amount->isCredit()));

        $payable = AccountMapping::forKeyOn('purchasing.accounts_payable', $to)?->account;

        return new self(
            supplier: $supplier,
            from: $from,
            to: $to,
            opening: $opening,
            charges: $charges,
            settlements: $settlements,
            lines: $lines,
            ledgerOpening: $payable === null ? null : self::partyBalance($payable->id, $supplier, fn ($query) => $query
                ->whereDate('journals.journal_date', '<', $fromDate)),
            ledgerClosing: $payable === null ? null : self::partyBalance($payable->id, $supplier, fn ($query) => $query
                ->whereDate('journals.journal_date', '<=', $toDate)),
        );
    }

    public function closing(): Money
    {
        return $this->opening->plus($this->charges)->plus($this->settlements);
    }

    public function hasIndependentCheck(): bool
    {
        return $this->ledgerOpening !== null && $this->ledgerClosing !== null;
    }

    public function isConsistent(): bool
    {
        return ! $this->hasIndependentCheck()
            || ($this->opening->minor === $this->ledgerOpening->minor
                && $this->closing()->minor === $this->ledgerClosing->minor);
    }

    public function ledgerClosing(): ?Money
    {
        return $this->ledgerClosing;
    }

    /**
     * The supplier's share of Accounts Payable, party-tagged lines only, in the
     * statement's own sign (owed = positive), so the negation of the ledger sum.
     *
     * @param  callable(Builder): Builder  $narrow
     */
    private static function partyBalance(int $accountId, Supplier $supplier, callable $narrow): Money
    {
        $query = JournalLine::query()
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journal_lines.account_id', $accountId)
            ->where('journal_lines.party_type', $supplier->getMorphClass())
            ->where('journal_lines.party_id', $supplier->id);

        return Money::ofMinor(-(int) $narrow($query)->sum('journal_lines.amount_minor'));
    }
}
