<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Foundation\Models\Business;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The statement of financial position, as at a date.
 *
 * ```
 *   ASSETS                    (1000 group, as at the date)
 *   ─────────────────────────
 *   LIABILITIES               (2000 group)
 *   EQUITY                    (3000 group)
 *     + Result brought forward       income − expense BEFORE the fiscal year
 *     + Result for the year to date  income − expense within the fiscal year
 *   ─────────────────────────
 *   Assets  =  Liabilities + Equity
 * ```
 *
 * ⚠⚠ C-FS-5. THE UNCLOSED RESULT IS WHAT MAKES IT BALANCE. `Assets =
 * Liabilities + Equity` only holds once income and expense have been closed into
 * equity at a year end. **This system has no year-end close** - nothing anywhere
 * zeroes an income account into retained earnings. So every taka of profit ever
 * earned is still sitting in the 4000/5000/6000 accounts, and a balance sheet
 * that ignored them would be out by exactly the accumulated profit. The result
 * is therefore computed and shown inside equity.
 *
 * ▶ This is standard presentation, not invented policy: there is no alternative
 * that balances. The accountant is asked to confirm the WORDING (C-FS-A), not
 * the arithmetic.
 *
 * ⚠⚠ C-FS-4. AS AT a date, not FOR a period. One date in, everything from the
 * beginning of the ledger up to and including it.
 *
 * ⚠ The split between "brought forward" and "for the year to date" falls out of
 * a single `TrialBalance` call: its `opening` is everything before the fiscal
 * year start, and its movement is everything within the year. One query, both
 * figures, no chance of the two disagreeing.
 */
final class BalanceSheet
{
    /**
     * @param  Collection<int, StatementSection>  $assets
     * @param  Collection<int, StatementSection>  $liabilities
     * @param  Collection<int, StatementSection>  $equity
     */
    private function __construct(
        public readonly Carbon $asAt,
        public readonly Carbon $fiscalYearStart,
        public readonly Collection $assets,
        public readonly Collection $liabilities,
        public readonly Collection $equity,
        public readonly Money $resultBroughtForward,
        public readonly Money $resultForTheYear,
    ) {}

    public static function asAt(Carbon $asAt, Business $business): self
    {
        $yearStart = FiscalYear::containing($asAt, $business)->startsOn();

        // ⚠ The window starts at the fiscal year so that `opening` means
        // "before this year" - which is exactly the brought-forward figure.
        $rows = TrialBalance::between($yearStart, $asAt)->rows;

        $balanceSheetRows = $rows->filter(fn (TrialBalanceRow $row) => $row->account->type->isBalanceSheet());
        $resultRows = $rows->reject(fn (TrialBalanceRow $row) => $row->account->type->isBalanceSheet());

        // Position accounts: the CLOSING balance, everything up to the date.
        $sections = StatementGrouping::sections(
            $balanceSheetRows,
            fn (TrialBalanceRow $row) => $row->closing(),
        );

        return new self(
            asAt: $asAt,
            fiscalYearStart: $yearStart,
            assets: StatementGrouping::under($sections, '1'),
            liabilities: StatementGrouping::under($sections, '2'),
            equity: StatementGrouping::under($sections, '3'),
            resultBroughtForward: Money::sum($resultRows->map(fn (TrialBalanceRow $row) => $row->opening)),
            resultForTheYear: Money::sum($resultRows->map(
                fn (TrialBalanceRow $row) => $row->debit->plus($row->credit)
            )),
        );
    }

    /**
     * ⚠ Assets are debit-normal, so the ledger's sign is already positive and
     * this reads straight. Liabilities and equity are credit-normal and negative
     * underneath; they are negated for presentation so the face of the statement
     * is ordinary addition (C-FS-3).
     */
    public function totalAssets(): Money
    {
        return self::sum($this->assets);
    }

    public function totalLiabilities(): Money
    {
        return self::sum($this->liabilities)->negated();
    }

    /**
     * Equity accounts alone, before the unclosed result is added.
     */
    public function totalEquityAccounts(): Money
    {
        return self::sum($this->equity)->negated();
    }

    /**
     * The accumulated result sitting unclosed in the income and expense
     * accounts, presented as a profit-positive figure.
     */
    public function unclosedResult(): Money
    {
        return $this->resultBroughtForward->plus($this->resultForTheYear)->negated();
    }

    public function presentedResultBroughtForward(): Money
    {
        return $this->resultBroughtForward->negated();
    }

    public function presentedResultForTheYear(): Money
    {
        return $this->resultForTheYear->negated();
    }

    /**
     * Total equity INCLUDING the unclosed result - the figure that faces total
     * assets.
     */
    public function totalEquity(): Money
    {
        return $this->totalEquityAccounts()->plus($this->unclosedResult());
    }

    public function totalLiabilitiesAndEquity(): Money
    {
        return $this->totalLiabilities()->plus($this->totalEquity());
    }

    /**
     * ⚠⚠ C-FS-6. Assets less liabilities and equity, in whole paisa. Must be
     * EXACTLY zero - not "within a taka", which is only a meaningful thing to
     * demand because every amount in this ledger is an integer number of paisa.
     *
     * ▶ It is zero for a structural reason, not a lucky one: the trial balance's
     * own difference is the sum of every account's closing balance, and this
     * statement partitions exactly those same closing balances into position
     * accounts and the unclosed result. If this is ever non-zero, the LEDGER is
     * broken, not the report - which is why it is shown rather than hidden.
     */
    public function difference(): Money
    {
        return $this->totalAssets()->plus($this->totalLiabilitiesAndEquity()->negated());
    }

    public function balances(): bool
    {
        return $this->difference()->isZero();
    }

    public function isEmpty(): bool
    {
        return $this->assets->isEmpty()
            && $this->liabilities->isEmpty()
            && $this->equity->isEmpty()
            && $this->unclosedResult()->isZero();
    }

    /**
     * @param  Collection<int, StatementSection>  $sections
     */
    private static function sum(Collection $sections): Money
    {
        return Money::sum($sections->map(fn (StatementSection $section) => $section->total()));
    }
}
