<?php

namespace App\Support\Dashboards;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The few ledger reads the dashboard widgets share. Posted journal lines only,
 * business filtered by hand (raw DB::table has no global scope), signed paisa
 * exactly as stored (debit +, credit -).
 *
 * ⚠ Income and cost sections are read by CODE PREFIX (4 income, 5 cost of
 * sales, 6 operating expenses), the same grouping IncomeStatement uses through
 * StatementGrouping, so a daily chart and the P&L for the same days agree.
 */
final class Ledger
{
    public static function lines(int $businessId): Builder
    {
        return DB::table('journal_lines')
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_lines.business_id', $businessId);
    }

    /**
     * Signed sum per journal date for accounts whose code starts with $prefix.
     *
     * @return array<string, int> Y-m-d => paisa
     */
    public static function dailyByPrefix(int $businessId, string $prefix, DateRange $range): array
    {
        return self::lines($businessId)
            ->where('accounts.code', 'like', $prefix.'%')
            ->whereBetween('journals.journal_date', [$range->fromDate(), $range->toDate()])
            ->groupBy('journals.journal_date')
            ->selectRaw('journals.journal_date as day, SUM(journal_lines.amount_minor) as total')
            ->pluck('total', 'day')
            ->mapWithKeys(fn ($total, $day) => [substr((string) $day, 0, 10) => (int) $total])
            ->all();
    }

    /** Signed total for a code prefix over a range. */
    public static function totalByPrefix(int $businessId, string $prefix, DateRange $range): int
    {
        return (int) self::lines($businessId)
            ->where('accounts.code', 'like', $prefix.'%')
            ->whereBetween('journals.journal_date', [$range->fromDate(), $range->toDate()])
            ->sum('journal_lines.amount_minor');
    }
}
