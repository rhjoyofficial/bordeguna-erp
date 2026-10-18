<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Purchasing\Models\Supplier;
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
 * Same day: customer lines first, then supplier lines, each in its own
 * statement's order, so two readers always see the same running net.
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
    ) {}

    public static function for(Supplier $supplier, Customer $customer, Carbon $from, Carbon $to): self
    {
        $customerSide = CustomerStatement::for($customer, $from, $to);
        $supplierSide = SupplierStatement::for($supplier, $from, $to);

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
            ->sortBy([
                fn (array $a, array $b) => strcmp($a['date']->toDateString(), $b['date']->toDateString()),
                ['rank', 'asc'],
                ['order', 'asc'],
            ])
            ->values();

        $net = Money::ofMinor($customerSide->opening->minor - $supplierSide->opening->minor);

        $lines = $merged->map(function (array $line) use (&$net) {
            $net = $net->plus($line['effect']);
            unset($line['rank'], $line['order']);

            return $line + ['net' => $net];
        });

        return new self($supplier, $customer, $customerSide, $supplierSide, $lines);
    }

    /** Positive = they owed us, at the start of the window. */
    public function openingNet(): Money
    {
        return Money::ofMinor($this->customerSide->opening->minor - $this->supplierSide->opening->minor);
    }

    public function closingNet(): Money
    {
        return Money::ofMinor($this->customerSide->closing()->minor - $this->supplierSide->closing()->minor);
    }

    /** Both halves agree with their own ledger heads (null when either cannot be checked). */
    public function consistent(): ?bool
    {
        if (! $this->customerSide->hasIndependentCheck() || ! $this->supplierSide->hasIndependentCheck()) {
            return null;
        }

        return $this->customerSide->isConsistent() && $this->supplierSide->isConsistent();
    }
}
