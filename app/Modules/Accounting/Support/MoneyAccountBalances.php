<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Foundation\Models\MoneyAccount;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What is in each money account right now, for a "Paid from" or "Received
 * into" picker (F20, practice run 17 Oct 2026: nobody could see whether BRAC
 * had enough before paying a supplier 14,960).
 *
 * ⚠ The same figure the Cash position report shows: every journal line on the
 * account's own ledger head (cash, bank and wallet accounts each have one,
 * MoneyLedgers). An account sharing a head with others (a courier on 1145) is
 * read by its own money_account_id on that head instead, so two never show the
 * combined figure. Display only; nothing is refused on it.
 */
final class MoneyAccountBalances
{
    /**
     * @param  Collection<int, MoneyAccount>  $accounts
     * @return array<int, string> money account id => balance in taka, two places
     */
    public static function for(Collection $accounts): array
    {
        $withLedger = $accounts->filter(fn (MoneyAccount $account) => $account->account_id !== null);

        if ($withLedger->isEmpty()) {
            return [];
        }

        $businessId = (int) $withLedger->first()->business_id;
        $own = $withLedger->filter(fn (MoneyAccount $account) => MoneyLedgers::hasOwnLedger($account->type));
        $shared = $withLedger->reject(fn (MoneyAccount $account) => MoneyLedgers::hasOwnLedger($account->type));

        $byHead = $own->isEmpty() ? collect() : DB::table('journal_lines')
            ->where('business_id', $businessId)
            ->whereIn('account_id', $own->pluck('account_id')->unique())
            ->groupBy('account_id')
            ->selectRaw('account_id, SUM(amount_minor) as minor')
            ->pluck('minor', 'account_id');

        $byAccount = $shared->isEmpty() ? collect() : DB::table('journal_lines')
            ->where('business_id', $businessId)
            ->whereIn('money_account_id', $shared->pluck('id'))
            ->whereIn('account_id', $shared->pluck('account_id')->unique())
            ->groupBy('money_account_id')
            ->selectRaw('money_account_id, SUM(amount_minor) as minor')
            ->pluck('minor', 'money_account_id');

        return $withLedger->mapWithKeys(fn (MoneyAccount $account) => [
            $account->id => Money::ofMinor((int) (MoneyLedgers::hasOwnLedger($account->type)
                ? ($byHead[$account->account_id] ?? 0)
                : ($byAccount[$account->id] ?? 0)))->toTaka(),
        ])->all();
    }
}
