<?php

namespace App\Modules\Inventory\Support;

use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Support\BusinessContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ Farm harvest (B9, 24 Oct 2026): which ledger heads hold a crop that is
 * still growing, and what each holds.
 *
 * ⚠⚠ THE RULE: an active, postable ASSET head coded 1152, or coded under it
 * (1152-01, 115201), or sitting under a head so coded. That is the code the
 * farm guide gave the Owner and the one he opened on live ("1152 Crops in
 * Progress - Brown Rice (Masum land)", a posting head under 1100), and the
 * guide's plan for more seasons is a 1152 group with one head per season
 * (1152-01, 1152-02). Both shapes pass, nothing else does.
 *
 * Why a code and not a flag on the account: the Owner opens these heads
 * himself in Accounts > Ledgers, and a flag would be one more box to tick
 * there (a chart screen change) and one more thing to forget; the code is
 * already what he types. Why not "any asset head": the harvest credits the
 * head it empties, and letting it empty the bank, a receivable or Stock on
 * Hand itself would be a journal that no other screen allows. The code lives
 * here, once.
 */
final class CropHeads
{
    public const CODE = '1152';

    /** @return Builder<Account> */
    public static function query(): Builder
    {
        $groupIds = Account::query()->where('code', 'like', self::CODE.'%')->pluck('id');

        return Account::query()
            ->where('type', AccountType::Asset->value)
            ->where('is_postable', true)
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q->where('code', 'like', self::CODE.'%')->orWhereIn('parent_id', $groupIds))
            ->inChartOrder();
    }

    public static function allows(?Account $account): bool
    {
        return $account !== null && self::query()->whereKey($account->id)->exists();
    }

    /** The head's balance (debit positive) on `$on`, or today. */
    public static function balanceMinor(Account $account, ?Carbon $on = null): int
    {
        $query = self::lines($account);

        if ($on !== null) {
            $query->whereDate('journals.journal_date', '<=', $on->toDateString());
        }

        return (int) $query->sum('journal_lines.amount_minor');
    }

    /**
     * ⚠⚠ The LOWEST the head stands on any day from `$on` onwards, the
     * MoneyAvailability rule. A harvest back-dated to the 10th must not take
     * more than the head held on the 15th, after a write-off dated the 12th
     * already took its share: the head would go below zero in between and a
     * balance sheet for that day would show a negative crop.
     */
    public static function availableFromMinor(Account $account, Carbon $on): int
    {
        $running = self::balanceMinor($account, $on);
        $lowest = $running;

        $later = self::lines($account)
            ->whereDate('journals.journal_date', '>', $on->toDateString())
            ->groupBy('journals.journal_date')
            ->orderBy('journals.journal_date')
            ->selectRaw('journals.journal_date, SUM(journal_lines.amount_minor) AS movement')
            ->pluck('movement');

        foreach ($later as $movement) {
            $running += (int) $movement;
            $lowest = min($lowest, $running);
        }

        return $lowest;
    }

    private static function lines(Account $account): QueryBuilder
    {
        return DB::table('journal_lines')
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journal_lines.account_id', $account->id)
            ->where('journals.business_id', app(BusinessContext::class)->id());
    }
}
