<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Every account's opening balance, movement and closing balance for a period.
 *
 * ⚠⚠ ONE QUERY, USED BY BOTH THE SCREEN AND THE VERIFIER. That is what makes
 * level 4 of `accounting:verify-ledger` worth anything: it checks the numbers a
 * user is actually SHOWN, not a second implementation that happens to agree.
 * This project has shipped a defect in a presentation path twice, which is why
 * `inventory:verify-ledger` grew that level in the first place.
 *
 * ⚠ Business scoping comes from `JournalLine`'s own global scope. This class
 * never adds `where business_id`, so it cannot disagree with the rest of the
 * system about which tenant it is in - and outside a tenant it returns nothing
 * rather than everything.
 */
final class TrialBalance
{
    /**
     * @param  Collection<int, TrialBalanceRow>  $rows
     */
    private function __construct(
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly Collection $rows,
    ) {}

    public static function between(Carbon $from, Carbon $to): self
    {
        // Everything before the window: the opening position.
        $opening = self::totals(fn ($query) => $query
            ->whereDate('journals.journal_date', '<', $from->toDateString()));

        // Inside the window, split by side. ⚠ Debits and credits are summed
        // separately rather than netted: an account with 900 in and 900 out has
        // a closing balance of zero and is not a quiet account, and a trial
        // balance that showed one blank line for it would hide the activity.
        $debits = self::totals(fn ($query) => $query
            ->whereDate('journals.journal_date', '>=', $from->toDateString())
            ->whereDate('journals.journal_date', '<=', $to->toDateString())
            ->where('journal_lines.amount_minor', '>', 0));

        $credits = self::totals(fn ($query) => $query
            ->whereDate('journals.journal_date', '>=', $from->toDateString())
            ->whereDate('journals.journal_date', '<=', $to->toDateString())
            ->where('journal_lines.amount_minor', '<', 0));

        $touched = collect([$opening->keys(), $debits->keys(), $credits->keys()])
            ->flatten()
            ->map(fn ($id) => (int) $id)
            ->unique();

        $accounts = Account::query()->whereIn('id', $touched)->inChartOrder()->get();

        $rows = $accounts->map(fn (Account $account) => new TrialBalanceRow(
            account: $account,
            opening: Money::ofMinor((int) ($opening[$account->id] ?? 0)),
            debit: Money::ofMinor((int) ($debits[$account->id] ?? 0)),
            credit: Money::ofMinor((int) ($credits[$account->id] ?? 0)),
        ));

        return new self($from, $to, $rows->values());
    }

    /**
     * ⚠ The whole point. Every debit in a correct ledger has an equal credit, so
     * the closing balances must net to exactly zero. Not "to within a taka" -
     * exactly, which is only a meaningful thing to ask because the amounts are
     * whole paisa.
     */
    public function difference(): Money
    {
        return Money::sum($this->rows->map(fn (TrialBalanceRow $row) => $row->closing()));
    }

    public function balances(): bool
    {
        return $this->difference()->isZero();
    }

    /**
     * The debit column's total. In a balanced ledger the credit column's total
     * is the same figure, which is what a reader checks first.
     */
    public function totalDebits(): Money
    {
        return Money::sum(
            $this->rows
                ->map(fn (TrialBalanceRow $row) => $row->closing())
                ->filter(fn (Money $amount) => $amount->isDebit())
        );
    }

    public function totalCredits(): Money
    {
        return Money::sum(
            $this->rows
                ->map(fn (TrialBalanceRow $row) => $row->closing())
                ->filter(fn (Money $amount) => $amount->isCredit())
        )->absolute();
    }

    public function rowFor(string $systemSlug): ?TrialBalanceRow
    {
        return $this->rows->first(fn (TrialBalanceRow $row) => $row->account->system_slug === $systemSlug);
    }

    /**
     * Sums `amount_minor` per account under whatever the caller narrows it to.
     *
     * ⚠ The date lives on the JOURNAL, not the line, so every one of these has
     * to join. The alternative - copying journal_date onto each line - would be
     * a third denormalised column to keep honest, and one is already enough.
     *
     * ⚠ `selectRaw` with a named alias rather than passing an expression to
     * `pluck`: pluck takes column NAMES, and handing it an aggregate expression
     * works by accident on one driver and not the other.
     *
     * @param  callable(Builder): Builder  $narrow
     * @return Collection<int, int>
     */
    private static function totals(callable $narrow): Collection
    {
        $query = JournalLine::query()
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->selectRaw('journal_lines.account_id as account_id, SUM(journal_lines.amount_minor) as total')
            ->groupBy('journal_lines.account_id');

        return $narrow($query)
            ->pluck('total', 'account_id')
            ->map(fn ($total) => (int) $total);
    }
}
