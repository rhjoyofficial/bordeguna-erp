<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Models\PartyAdjustment;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerReceipt;
use App\Modules\Sales\Models\CustomerRefund;
use App\Modules\Sales\Models\SalesCreditNote;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesReturn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One customer's account for a period: every movement, in order, with a running
 * balance and what they owe at the end.
 *
 * ⚠⚠ C-CST-1: BUILT FROM DOCUMENTS, CHECKED BY THE LEDGER. The lines carry the
 * invoice and receipt numbers a dealer recognises; the party-filtered Accounts
 * Receivable balance - the same party-tagged journal lines that
 * `accounting:reconcile` reads - independently computes the opening and closing,
 * and the screen states whether the two agree. That is the C-GLA-12 pattern:
 * two genuinely different paths to one number, not a number beside itself.
 *
 * ⚠ C-CST-2: allocations do not appear. A receipt reduces what the customer
 * owes whether or not it is allocated to an invoice; which invoices remain OPEN
 * is the ageing's question. With no allocation terms, the Ageing identity
 * (Σinvoices - Σreceipts - Σcredits + Σrefunds) holds here per customer.
 *
 * ⚠ Business scoping comes from each model's own global scope, as in `Ageing`.
 */
final class CustomerStatement
{
    /**
     * @param  Collection<int, CustomerStatementLine>  $lines
     */
    private function __construct(
        public readonly Customer $customer,
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly Money $opening,
        public readonly Money $charges,
        public readonly Money $settlements,
        public readonly Collection $lines,
        private readonly ?Money $ledgerOpening,
        private readonly ?Money $ledgerClosing,
    ) {}

    public static function for(Customer $customer, Carbon $from, Carbon $to): self
    {
        // ⚠ C-CST-5: every window comparison is a DATE-STRING comparison. The
        // date-cast trap has bitten this project five times.
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();

        // Each document as [date, kind, id, label, reference, signed amount].
        // ⚠ C-CST-3: kind RANK is part of the order below, so the list is a
        // total order and two readers see the same running balances.
        $movements = collect()
            ->concat(SalesInvoice::query()
                ->where('customer_id', $customer->id)
                ->whereDate('invoice_date', '<=', $toDate)
                ->get()
                ->map(fn (SalesInvoice $invoice) => [
                    'date' => Carbon::parse($invoice->invoice_date->toDateString()),
                    'kind' => 'invoice',
                    'rank' => 0,
                    'id' => $invoice->id,
                    'label' => "Invoice {$invoice->invoice_number}",
                    'reference' => null,
                    'amount' => Money::ofTaka((string) $invoice->total),
                ]))
            ->concat(SalesReturn::query()
                ->where('customer_id', $customer->id)
                ->whereDate('returned_at', '<=', $toDate)
                ->get()
                ->map(fn (SalesReturn $return) => [
                    'date' => Carbon::parse($return->returned_at->toDateString()),
                    'kind' => 'return',
                    'rank' => 1,
                    'id' => $return->id,
                    'label' => "Return {$return->return_number}",
                    'reference' => null,
                    'amount' => Money::ofTaka((string) $return->credit_total)->negated(),
                ]))
            ->concat(CustomerReceipt::query()
                ->where('customer_id', $customer->id)
                ->whereDate('received_on', '<=', $toDate)
                ->get()
                ->map(fn (CustomerReceipt $receipt) => [
                    'date' => Carbon::parse($receipt->received_on->toDateString()),
                    'kind' => 'receipt',
                    'rank' => 2,
                    'id' => $receipt->id,
                    'label' => 'Payment received',
                    'reference' => $receipt->reference,
                    'amount' => Money::ofTaka((string) $receipt->amount)->negated(),
                ]))
            ->concat(CustomerRefund::query()
                ->where('customer_id', $customer->id)
                ->whereDate('refunded_on', '<=', $toDate)
                ->get()
                ->map(fn (CustomerRefund $refund) => [
                    'date' => Carbon::parse($refund->refunded_on->toDateString()),
                    'kind' => 'refund',
                    'rank' => 3,
                    'id' => $refund->id,
                    'label' => 'Refund paid',
                    'reference' => $refund->reference,
                    'amount' => Money::ofTaka((string) $refund->amount),
                ]))
            // ⭐ Corrections wave 1 (20 Oct 2026): an invoice credited in full and
            // a receipt reversed stay on the statement on their own day, and the
            // undoing lands on the day the mirror journal does (the
            // SupplierStatement shape, PUR-003), so the running balance follows
            // 1140 day by day.
            ->concat(SalesInvoice::query()
                ->where('customer_id', $customer->id)
                ->whereNotNull('reversed_on')
                ->whereDate('reversed_on', '<=', $toDate)
                ->get()
                ->map(fn (SalesInvoice $invoice) => [
                    'date' => Carbon::parse($invoice->reversed_on->toDateString()),
                    'kind' => 'credit_note',
                    'rank' => 1,
                    'id' => $invoice->id,
                    'label' => 'Credit note '.(SalesCreditNote::query()->where('sales_invoice_id', $invoice->id)->where('kind', SalesCreditNote::FULL)->value('credit_note_number') ?? '')." on {$invoice->invoice_number}",
                    'reference' => $invoice->reversal_reason,
                    'amount' => Money::ofTaka((string) $invoice->total)->negated(),
                ]))
            ->concat(CustomerReceipt::query()
                ->where('customer_id', $customer->id)
                ->whereNotNull('reversed_on')
                ->whereDate('reversed_on', '<=', $toDate)
                ->get()
                ->map(fn (CustomerReceipt $receipt) => [
                    'date' => Carbon::parse($receipt->reversed_on->toDateString()),
                    'kind' => 'receipt_reversed',
                    'rank' => 3,
                    'id' => $receipt->id,
                    'label' => 'Payment reversed',
                    'reference' => $receipt->reference,
                    'amount' => Money::ofTaka((string) $receipt->amount),
                ]))
            ->concat(PartyAdjustment::query()
                ->where('customer_id', $customer->id)
                ->whereDate('adjusted_on', '<=', $toDate)
                ->get()
                ->map(fn (PartyAdjustment $adjustment) => [
                    'date' => Carbon::parse($adjustment->adjusted_on->toDateString()),
                    'kind' => 'adjustment',
                    'rank' => 4,
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

        // The check side. Null when Accounts Receivable is unmapped - then
        // there is nothing to check against and the screen says nothing rather
        // than showing a tick that was never tested (the C-GLA-7 stance).
        $receivable = AccountMapping::forKeyOn('sales.accounts_receivable', $to)?->account;

        return new self(
            customer: $customer,
            from: $from,
            to: $to,
            opening: $opening,
            charges: $charges,
            settlements: $settlements,
            lines: $lines,
            ledgerOpening: $receivable === null
                ? null
                : self::partyBalanceBefore($receivable->id, $customer, $fromDate),
            ledgerClosing: $receivable === null
                ? null
                : self::partyBalanceThrough($receivable->id, $customer, $toDate),
        );
    }

    public function closing(): Money
    {
        return $this->opening->plus($this->charges)->plus($this->settlements);
    }

    /**
     * ⚠⚠ C-CST-1. Documents against party-tagged postings, exactly, in whole
     * paisa - BOTH ends of the window. A disagreement means a posting without a
     * document or a document without a posting, which is a finding, not a bug
     * in the report; `accounting:reconcile` names the culprit.
     */
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
     * The customer's share of Accounts Receivable: party-tagged lines only,
     * matched via `getMorphClass()`, never a hand-typed string.
     */
    private static function partyBalanceBefore(int $accountId, Customer $customer, string $fromDate): Money
    {
        return self::partyBalance($accountId, $customer, fn ($query) => $query
            ->whereDate('journals.journal_date', '<', $fromDate));
    }

    private static function partyBalanceThrough(int $accountId, Customer $customer, string $toDate): Money
    {
        return self::partyBalance($accountId, $customer, fn ($query) => $query
            ->whereDate('journals.journal_date', '<=', $toDate));
    }

    /**
     * @param  callable(Builder): Builder  $narrow
     */
    private static function partyBalance(int $accountId, Customer $customer, callable $narrow): Money
    {
        $query = JournalLine::query()
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journal_lines.account_id', $accountId)
            ->where('journal_lines.party_type', $customer->getMorphClass())
            ->where('journal_lines.party_id', $customer->id);

        return Money::ofMinor((int) $narrow($query)->sum('journal_lines.amount_minor'));
    }
}
