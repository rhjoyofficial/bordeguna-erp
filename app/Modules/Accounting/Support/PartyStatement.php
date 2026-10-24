<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Models\SupplierAdvance;
use App\Modules\Purchasing\Models\SupplierAdvanceApplication;
use App\Modules\Purchasing\Models\SupplierAdvanceRefund;
use App\Modules\Sales\Models\Customer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * ⭐ The combined party statement (decision D-F, 18 Oct 2026): one firm that is
 * both our supplier and our customer, both accounts on one page, with a
 * running NET for a date range.
 *
 * ⚠⚠ BUILT FROM THE TWO STATEMENTS THAT ALREADY EXIST, never a third reading
 * of the documents: CustomerStatement (what they owe us, checked against the
 * party-tagged 1140) and SupplierStatement (what we owe them, checked against
 * 2110). Each line keeps its own side; the net is theirs minus ours.
 *
 * Sign: every line's `effect` is on the net from our side, positive = they owe
 * us more. An invoice adds, a bill subtracts, a payment we make adds back, a
 * receipt subtracts. ⭐ A set-off appears on BOTH sides (the invoice side down,
 * the bill side down) and its two effects cancel: netting moves nothing in the
 * net, which is exactly the point of it.
 *
 * Same day: customer lines first, then supplier lines, then advance lines,
 * each in its own order, so two readers always see the same running net.
 *
 * ⭐ OUR ADVANCE WITH THEM (owner request 20 Oct 2026, the Ayesha case: the
 * statement said "They owe us 39,931" while the truth was 57,331, the invoice
 * plus an unused advance of 17,400). Money we paid ahead of a bill is still
 * ours until goods or a refund come back for it, so it is a THIRD side of the
 * net: an advance paid adds (they hold more of ours), a refund subtracts, and
 * an advance used on a bill subtracts here while the same amount lowers the
 * bill on the supplier side, so applying an advance moves nothing in the net,
 * exactly like a set-off.
 *
 * ⚠ The advance side is READ FROM THE LEDGER (the supplier's party-tagged
 * lines on 1160), not from advance documents: part of 1160 has no document
 * behind it (the on-account pool of Payment vouchers and journals, see
 * SupplierAdvanceBook), and leaving it out is the very gap this closes.
 */
final class PartyStatement
{
    /**
     * @param  Collection<int, array{date: Carbon, side: string, kind: string, label: string, reference: ?string, amount: Money, effect: Money, net: Money}>  $lines
     */
    private function __construct(
        public readonly Supplier $supplier,
        public readonly Customer $customer,
        public readonly CustomerStatement $customerSide,
        public readonly SupplierStatement $supplierSide,
        public readonly Collection $lines,
        public readonly Money $advanceOpening,
        public readonly Money $advanceClosing,
        private readonly ?Money $advanceLedgerClosing,
    ) {}

    public static function for(Supplier $supplier, Customer $customer, Carbon $from, Carbon $to): self
    {
        $customerSide = CustomerStatement::for($customer, $from, $to);
        $supplierSide = SupplierStatement::for($supplier, $from, $to);
        [$advanceOpening, $advanceLines, $advanceLedgerClosing] = self::advances($supplier, $from, $to);

        $merged = collect()
            ->concat($customerSide->lines->values()->map(fn (CustomerStatementLine $line, int $i) => [
                'date' => $line->date, 'rank' => 0, 'order' => $i, 'side' => 'customer', 'kind' => $line->kind,
                'label' => $line->label, 'reference' => $line->reference, 'amount' => $line->amount,
                'effect' => $line->amount,
            ]))
            ->concat($supplierSide->lines->values()->map(fn (CustomerStatementLine $line, int $i) => [
                'date' => $line->date, 'rank' => 1, 'order' => $i, 'side' => 'supplier', 'kind' => $line->kind,
                'label' => $line->label, 'reference' => $line->reference, 'amount' => $line->amount,
                'effect' => $line->amount->negated(),
            ]))
            ->concat($advanceLines->values()->map(fn (array $line, int $i) => $line + ['rank' => 2, 'order' => $i]))
            ->sortBy([
                fn (array $a, array $b) => strcmp($a['date']->toDateString(), $b['date']->toDateString()),
                ['rank', 'asc'],
                ['order', 'asc'],
            ])
            ->values();

        $net = Money::ofMinor($customerSide->opening->minor - $supplierSide->opening->minor + $advanceOpening->minor);

        $lines = $merged->map(function (array $line) use (&$net) {
            $net = $net->plus($line['effect']);
            unset($line['rank'], $line['order']);

            return $line + ['net' => $net];
        });

        $advanceClosing = $advanceOpening->plus(Money::sum($advanceLines->map(fn (array $line) => $line['amount'])));

        return new self($supplier, $customer, $customerSide, $supplierSide, $lines, $advanceOpening, $advanceClosing, $advanceLedgerClosing);
    }

    /** Positive = they owed us, at the start of the window (our advance with them counts as theirs to return). */
    public function openingNet(): Money
    {
        return Money::ofMinor($this->customerSide->opening->minor - $this->supplierSide->opening->minor + $this->advanceOpening->minor);
    }

    public function closingNet(): Money
    {
        return Money::ofMinor($this->customerSide->closing()->minor - $this->supplierSide->closing()->minor + $this->advanceClosing->minor);
    }

    /** All three parts agree with their own ledger heads (null when one cannot be checked). */
    public function consistent(): ?bool
    {
        if (! $this->customerSide->hasIndependentCheck() || ! $this->supplierSide->hasIndependentCheck() || $this->advanceLedgerClosing === null) {
            return null;
        }

        return $this->customerSide->isConsistent()
            && $this->supplierSide->isConsistent()
            && $this->advanceClosing->minor === $this->advanceLedgerClosing->minor;
    }

    /**
     * The supplier's party-tagged lines on 1160 Advances to Suppliers: the
     * opening before the window, the window's lines (debit = we paid them
     * ahead, credit = used on a bill or given back), and an independent sum to
     * the window's end as the check.
     *
     * ⭐ Public since B23 (25 Oct 2026): the supplier page's lifetime timeline
     * reads the same advance side for a supplier who is not also a customer.
     *
     * @return array{0: Money, 1: Collection<int, array<string, mixed>>, 2: ?Money}
     */
    public static function advances(Supplier $supplier, Carbon $from, Carbon $to): array
    {
        $account = Account::bySlug('supplier_advances');

        if ($account === null) {
            return [Money::zero(), collect(), null];
        }

        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();
        $partied = fn () => JournalLine::query()
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journal_lines.account_id', $account->id)
            ->where('journal_lines.party_type', $supplier->getMorphClass())
            ->where('journal_lines.party_id', $supplier->id);

        // ⚠ Date-string comparisons (C-CST-5), as every statement.
        $opening = Money::ofMinor((int) $partied()->whereDate('journals.journal_date', '<', $fromDate)->sum('journal_lines.amount_minor'));
        $ledgerClosing = Money::ofMinor((int) $partied()->whereDate('journals.journal_date', '<=', $toDate)->sum('journal_lines.amount_minor'));

        $lines = $partied()
            ->whereDate('journals.journal_date', '>=', $fromDate)
            ->whereDate('journals.journal_date', '<=', $toDate)
            ->orderBy('journals.journal_date')
            ->orderBy('journals.id')
            ->orderBy('journal_lines.line_no')
            ->select('journal_lines.*')
            ->with(['journal', 'journal.source'])
            ->get()
            ->map(function (JournalLine $line) {
                $journal = $line->journal;
                [$kind, $label] = self::describeAdvance($journal, $line->isDebit());
                $amount = Money::ofMinor((int) $line->amount_minor);
                $source = $journal->source;

                return [
                    'date' => Carbon::parse($journal->journal_date->toDateString()),
                    'side' => 'advance',
                    'kind' => $kind,
                    'label' => $label,
                    'reference' => $source instanceof SupplierAdvance || $source instanceof SupplierAdvanceRefund
                        ? ($source->reference ?: null)
                        : null,
                    // Signed as our advance reads it: positive = they hold more of ours.
                    'amount' => $amount,
                    'effect' => $amount,
                ];
            })
            ->values();

        return [$opening, $lines, $ledgerClosing];
    }

    /** @return array{0: string, 1: string} the line's kind and the words a reader sees */
    private static function describeAdvance(Journal $journal, bool $isDebit): array
    {
        $source = $journal->source;

        if ($source instanceof SupplierAdvance) {
            [$kind, $label] = $source->kind === 'opening'
                ? ['advance_opening', "Opening advance {$source->number}"]
                : ['advance_paid', "Advance paid {$source->number}"];
        } elseif ($source instanceof SupplierAdvanceRefund) {
            [$kind, $label] = ['advance_refunded', "Advance refunded {$source->number}"];
        } elseif ($source instanceof SupplierAdvanceApplication) {
            [$kind, $label] = ['advance_applied', "Advance {$source->number} used on bill {$source->bill?->bill_number}"];
        } else {
            // On account: a Payment voucher, a journal, an overpayment.
            $document = SupplierLedger::document($journal);
            $kind = $isDebit ? 'advance_paid' : 'advance_reduced';
            $label = trim(($isDebit ? 'Advance paid on account: ' : 'Advance reduced on account: ').$document['label'].' '.($document['number'] ?? ''));

            // The document label already says "(reversal)" on a mirror journal.
            return [$journal->reverses_journal_id !== null ? "{$kind}_reversed" : $kind, $label];
        }

        return $journal->reverses_journal_id !== null ? ["{$kind}_reversed", "{$label} reversed"] : [$kind, $label];
    }
}
