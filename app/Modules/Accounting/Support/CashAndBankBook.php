<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Models\Account;
use App\Modules\Foundation\Models\MoneyAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Where the business's money is and how it moved: one row per ledger account
 * that a money account reports under, for a period.
 *
 * ⚠⚠ C-CBB-2: every figure is read from `TrialBalance::between`, the same query
 * the trial balance screen and level 4 of `accounting:verify-ledger` already
 * run. Nothing is re-summed here, which is also why this screen shows no
 * consistency tick: it would compare a number with itself, the decoration the
 * General Ledger slice documented and refused.
 *
 * ⚠ Business scoping: the postings are scoped by `JournalLine`'s global scope
 * inside TrialBalance, and `MoneyAccount` carries `BelongsToBusiness`. This
 * class writes no `where business_id` of its own.
 */
final class CashAndBankBook
{
    /**
     * @param  Collection<int, CashAndBankRow>  $rows
     * @param  Collection<int, MoneyAccount>  $unmapped
     */
    private function __construct(
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly Collection $rows,
        public readonly Collection $unmapped,
    ) {}

    public static function between(Carbon $from, Carbon $to): self
    {
        $moneyAccounts = MoneyAccount::query()->orderBy('name')->get();

        // ⚠ C-CBB-4: an unmapped money account appears in NONE of these figures,
        // and the column is nullable on purpose ("a cash box may exist before
        // anybody has decided what it reports under"). The report names what it
        // cannot see rather than looking complete.
        $unmapped = $moneyAccounts->whereNull('account_id')->values();

        $byLedgerAccount = $moneyAccounts->whereNotNull('account_id')->groupBy('account_id');

        $trialBalance = TrialBalance::between($from, $to);

        $rows = Account::query()
            ->whereIn('id', $byLedgerAccount->keys()->all())
            ->inChartOrder()
            ->get()
            ->map(function (Account $account) use ($byLedgerAccount, $trialBalance) {
                $row = $trialBalance->rows->first(
                    fn (TrialBalanceRow $candidate) => $candidate->account->id === $account->id
                );

                return new CashAndBankRow(
                    account: $account,
                    moneyAccounts: $byLedgerAccount->get($account->id)->values(),
                    opening: $row?->opening ?? Money::zero(),
                    debit: $row?->debit ?? Money::zero(),
                    credit: $row?->credit ?? Money::zero(),
                );
            })
            ->filter(fn (CashAndBankRow $row) => $row->isWorthShowing())
            ->values();

        return new self($from, $to, $rows, $unmapped);
    }

    public function opening(): Money
    {
        return Money::sum($this->rows->map(fn (CashAndBankRow $row) => $row->opening));
    }

    public function moneyIn(): Money
    {
        return Money::sum($this->rows->map(fn (CashAndBankRow $row) => $row->debit));
    }

    public function moneyOut(): Money
    {
        return Money::sum($this->rows->map(fn (CashAndBankRow $row) => $row->credit));
    }

    public function closing(): Money
    {
        return Money::sum($this->rows->map(fn (CashAndBankRow $row) => $row->closing()));
    }
}
