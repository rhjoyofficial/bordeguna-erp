<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Enums\NormalBalance;
use Illuminate\Support\Collection;

/**
 * One headed group on a financial statement, and its total.
 *
 * A section is a top-level chart group - Current Assets, Cost of Sales,
 * Operating Expenses - holding the postable accounts beneath it.
 *
 * ⚠⚠ THE GROUPING COMES FROM THE CHART, NOT FROM A LIST IN THE CODE. The chart
 * already knows that 1100 is Current Assets and that 5000 Cost of Sales is
 * separate from 6000 Operating Expenses; that separation is what makes a gross
 * margin possible. A hard-coded classification here would be a second opinion
 * about the same fact, and it would silently stop matching the day somebody adds
 * an account (C-FS-B: moving 4900 out of gross profit is then a CHART change,
 * not a code change).
 *
 * ⚠ Amounts stay SIGNED all the way through. `total` is the ledger's own sign -
 * credit-normal sections are negative - and only the presentation layer flips
 * them for display. A minus in a heading and a minus in the arithmetic are not
 * the same minus, and mixing them is how a statement subtracts twice (C-FS-3).
 */
final class StatementSection
{
    /**
     * @param  Collection<int, StatementLine>  $lines
     * @param  ?NormalBalance  $normal  the heading account's normal side, which decides
     *                                  whether the section total reads as a deduction
     */
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly Collection $lines,
        public readonly ?NormalBalance $normal = null,
    ) {}

    /**
     * ⚠ Summed from the LINES, never queried separately. A section total that
     * disagreed with the rows printed under it is the classic report defect, and
     * the only way to make it impossible is to have one source.
     */
    public function total(): Money
    {
        return Money::sum($this->lines->map(fn (StatementLine $line) => $line->amount));
    }

    public function isEmpty(): bool
    {
        return $this->lines->isEmpty();
    }

    /**
     * The figure a reader sees: always positive, because the heading already
     * says whether it is income or expense.
     */
    public function presentedTotal(): Money
    {
        return $this->total()->absolute();
    }

    /**
     * True when the section AS A WHOLE sits on the opposite side from its
     * heading: an income group holding only a sales return, a receivables group
     * that is net credit. The screen prints it in brackets, exactly as it prints
     * a contra line, so a group always reads as the sum of the lines under it
     * (F31, 17 Oct 2026: "4000 Income" printed 807.50 above a child of (807.50)).
     */
    public function isContra(): bool
    {
        $total = $this->total();

        if ($this->normal === null || $total->isZero()) {
            return false;
        }

        return $this->normal === NormalBalance::Debit ? $total->isCredit() : $total->isDebit();
    }
}
