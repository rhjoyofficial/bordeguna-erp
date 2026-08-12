<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Models\Account;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The profit and loss for a period.
 *
 * ```
 *   Income                    (4000, net of contra 4200 / 4210)
 *   less Cost of Sales        (5000)
 *   ─────────────────────────
 *   GROSS PROFIT
 *   less Operating Expenses   (6000)
 *   ─────────────────────────
 *   NET PROFIT for the period
 * ```
 *
 * ⚠⚠ C-FS-1. IT USES PERIOD MOVEMENT, NEVER THE CLOSING BALANCE. A
 * `TrialBalanceRow`'s `closing()` is `opening + debit + credit`, and for an
 * income account the opening carries every prior period back to the beginning of
 * the ledger, because **no year-end close has ever run in this system**. Using
 * `closing()` would file the whole history under this month's heading. The line
 * is `debit + credit` for the window and nothing else.
 *
 * ⚠⚠ ONE QUERY PATH. Built from `TrialBalance`, the same rows the trial balance
 * screen and `accounting:verify-ledger` level 4 read. A statement that summed
 * the ledger itself would be a second implementation that happens to agree until
 * the day it does not.
 *
 * ⚠ The grouping is the CHART's, not a list in this file. 5000 Cost of Sales
 * being separate from 6000 Operating Expenses is what makes a gross margin
 * possible, and it is already modelled.
 */
final class IncomeStatement
{
    /**
     * @param  Collection<int, StatementSection>  $income
     * @param  Collection<int, StatementSection>  $costOfSales
     * @param  Collection<int, StatementSection>  $operatingExpenses
     */
    private function __construct(
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly Collection $income,
        public readonly Collection $costOfSales,
        public readonly Collection $operatingExpenses,
    ) {}

    public static function between(Carbon $from, Carbon $to): self
    {
        $rows = TrialBalance::between($from, $to)->rows;

        // ⚠ C-FS-1 in one line: the MOVEMENT, not the closing balance.
        $movement = fn (TrialBalanceRow $row) => $row->debit->plus($row->credit);

        $sections = StatementGrouping::sections(
            $rows->filter(fn (TrialBalanceRow $row) => ! $row->account->type->isBalanceSheet()),
            $movement,
        );

        return new self(
            from: $from,
            to: $to,
            income: StatementGrouping::under($sections, '4'),
            costOfSales: StatementGrouping::under($sections, '5'),
            operatingExpenses: StatementGrouping::under($sections, '6'),
        );
    }

    /**
     * ⚠ Income is credit-normal and therefore NEGATIVE in the ledger's sign.
     * This returns it presented - positive - because the heading already says
     * "Income". Every total below follows the same convention, so the subtraction
     * on the face of the statement is ordinary arithmetic a reader can check.
     */
    public function totalIncome(): Money
    {
        return self::sum($this->income)->negated();
    }

    public function totalCostOfSales(): Money
    {
        return self::sum($this->costOfSales);
    }

    public function grossProfit(): Money
    {
        return $this->totalIncome()->plus($this->totalCostOfSales()->negated());
    }

    public function totalOperatingExpenses(): Money
    {
        return self::sum($this->operatingExpenses);
    }

    public function netProfit(): Money
    {
        return $this->grossProfit()->plus($this->totalOperatingExpenses()->negated());
    }

    /**
     * Gross profit as a percentage of income, or null when there was no income.
     *
     * ⚠ Returns null rather than zero on no income. A margin of "0%" on a month
     * with no sales is a statement about the margin; there wasn't one.
     */
    public function grossMarginPercent(): ?float
    {
        if ($this->totalIncome()->isZero()) {
            return null;
        }

        return round($this->grossProfit()->minor / $this->totalIncome()->minor * 100, 2);
    }

    public function isEmpty(): bool
    {
        return $this->income->isEmpty()
            && $this->costOfSales->isEmpty()
            && $this->operatingExpenses->isEmpty();
    }

    /**
     * ⚠⚠ The tie-back that makes this statement trustworthy: the net profit here
     * must equal the net movement of every non-balance-sheet account in the same
     * window. Asserted in the tests rather than assumed, because "the report and
     * the ledger agree" is exactly the property a report cannot prove about
     * itself.
     */
    public function signedNetMovement(): Money
    {
        return self::sum($this->income)
            ->plus(self::sum($this->costOfSales))
            ->plus(self::sum($this->operatingExpenses));
    }

    /**
     * @param  Collection<int, StatementSection>  $sections
     */
    private static function sum(Collection $sections): Money
    {
        return Money::sum($sections->map(fn (StatementSection $section) => $section->total()));
    }
}
