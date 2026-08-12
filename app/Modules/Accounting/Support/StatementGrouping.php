<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Models\Account;
use Illuminate\Support\Collection;

/**
 * Turns trial-balance rows into the headed sections a statement is printed in.
 *
 * ⚠⚠ THE SECTION IS THE NEAREST HEADER ANCESTOR, NOT A FIXED DEPTH. Bor De
 * Guna's chart nests to different depths on purpose:
 *
 *   1110 Cash in Hand  →  1100 Current Assets  →  1000 Assets     (two levels)
 *   6100 Salaries      →  6000 Operating Expenses                 (one level)
 *
 * A rule of "group by the second level" would put salaries under a section that
 * does not exist, and a rule of "group by the root" would collapse Current and
 * Non-Current Assets into one block and destroy the only distinction a balance
 * sheet reader is looking for. Walking up to the first NON-POSTABLE account
 * gets both right, because a non-postable account is precisely what a heading
 * is - it exists to be totalled, never posted to.
 *
 * ⚠ Every account is loaded once and the walk happens in memory. Following
 * `parent()` per row would be an N+1 over a chart that is 63 rows long, and the
 * statement would get slower every time the chart grew.
 */
final class StatementGrouping
{
    /**
     * @param  Collection<int, TrialBalanceRow>  $rows
     * @param  callable(TrialBalanceRow): Money  $amountOf
     * @return Collection<int, StatementSection>
     */
    public static function sections(Collection $rows, callable $amountOf): Collection
    {
        if ($rows->isEmpty()) {
            return collect();
        }

        /** @var Collection<int, Account> $chart */
        $chart = Account::query()->withoutGlobalScopes()->get()->keyBy('id');

        $grouped = [];

        foreach ($rows as $row) {
            $amount = $amountOf($row);

            // ⚠ A row whose figure is zero for THIS statement is dropped here
            // rather than by the caller: an account with movement in a different
            // period legitimately appears on the trial balance and has no place
            // on this month's profit and loss (C-FS-9).
            if ($amount->isZero()) {
                continue;
            }

            $section = self::headerFor($row->account, $chart);

            $grouped[$section->code] ??= [
                'account' => $section,
                'lines' => [],
            ];

            $grouped[$section->code]['lines'][] = new StatementLine($row->account, $amount);
        }

        return collect($grouped)
            // Chart order, so the statement reads in the order the chart was
            // designed to be read in.
            ->sortKeys()
            ->map(fn (array $group) => new StatementSection(
                code: $group['account']->code,
                name: $group['account']->name,
                lines: collect($group['lines']),
            ))
            ->values();
    }

    /**
     * Sections whose code begins with the given digit - the statement's own
     * top-level division (4 income, 5 cost of sales, 6 operating expenses).
     *
     * @param  Collection<int, StatementSection>  $sections
     * @return Collection<int, StatementSection>
     */
    public static function under(Collection $sections, string $codePrefix): Collection
    {
        return $sections
            ->filter(fn (StatementSection $section) => str_starts_with($section->code, $codePrefix))
            ->values();
    }

    /**
     * The nearest ancestor that is a heading rather than a postable account.
     *
     * ⚠ Falls back to the account itself when it has no header above it - a
     * postable account sitting at the root is unusual but legal, and a statement
     * that silently dropped it would be worse than one that gives it its own
     * heading. The loop is also bounded: a chart that somehow contained a cycle
     * would hang the report rather than draw it, and a hung report on a live
     * screen is indistinguishable from an outage.
     *
     * @param  Collection<int, Account>  $chart
     */
    private static function headerFor(Account $account, Collection $chart): Account
    {
        $current = $account;

        for ($depth = 0; $depth < 20; $depth++) {
            $parentId = $current->parent_id;

            if ($parentId === null) {
                return $current;
            }

            $parent = $chart->get($parentId);

            if ($parent === null) {
                return $current;
            }

            if (! $parent->is_postable) {
                return $parent;
            }

            $current = $parent;
        }

        return $account;
    }
}
