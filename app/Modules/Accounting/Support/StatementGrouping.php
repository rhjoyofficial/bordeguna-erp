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

            [$section, $group] = self::headersFor($row->account, $chart);

            $grouped[$section->code] ??= [
                'account' => $section,
                'lines' => [],
            ];

            $grouped[$section->code]['lines'][] = new StatementLine($row->account, $amount, $group);
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
     * The section a head prints under, and the sub-group inside it, if any.
     *
     * ⚠⚠ THE SECTION IS THE HEADING JUST BELOW THE ROOT, and a deeper heading is
     * a SUB-GROUP inside it (21 Sep 2026). Until then every group in the chart
     * sat one level below a root, so "nearest heading" and "heading below the
     * root" were the same account and every statement printed exactly as it
     * does now. Then cash, bank and wallets became groups under 1100:
     *
     *   11201 BRAC Bank  →  1120 Bank Accounts  →  1100 Current Assets  →  1000 Assets
     *
     * Nearest-heading would have printed Bank Accounts as its own section beside
     * Current Assets, leaving the bank OUT of the current-assets total. So the
     * section stays 1100 and Bank Accounts becomes a subtotal inside it.
     *
     * ⚠ Falls back to the account itself when it has no header above it - a
     * postable account sitting at the root is unusual but legal, and a statement
     * that silently dropped it would be worse than one that gives it its own
     * heading. The loop is also bounded: a chart that somehow contained a cycle
     * would hang the report rather than draw it, and a hung report on a live
     * screen is indistinguishable from an outage.
     *
     * @param  Collection<int, Account>  $chart
     * @return array{0: Account, 1: ?Account} [section, sub-group]
     */
    private static function headersFor(Account $account, Collection $chart): array
    {
        /** @var list<Account> $headings nearest first */
        $headings = [];
        $current = $account;
        $reachedRoot = false;

        for ($depth = 0; $depth < 20; $depth++) {
            $parent = $current->parent_id === null ? null : $chart->get($current->parent_id);

            if ($parent === null) {
                $reachedRoot = $current->parent_id === null;
                break;
            }

            if (! $parent->is_postable) {
                $headings[] = $parent;
            }

            $current = $parent;
        }

        if ($headings === []) {
            return [$account, null];
        }

        $last = count($headings) - 1;
        // The top heading is the root itself: the section is the one below it.
        $rootIsHeading = $reachedRoot && $headings[$last]->id === $current->id;
        $section = $rootIsHeading && $last > 0 ? $headings[$last - 1] : $headings[$last];

        return [$section, $headings[0]->id === $section->id ? null : $headings[0]];
    }
}
