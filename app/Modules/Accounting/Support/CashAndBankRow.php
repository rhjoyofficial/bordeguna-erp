<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Models\Account;
use Illuminate\Support\Collection;

/**
 * One ledger account's line on the cash and bank book, with the money accounts
 * that report under it.
 *
 * ⚠ C-CBB-1: the grain is the LEDGER account. Three bank accounts may all
 * report as "Cash at Bank", and the ledger records postings per account only -
 * a per-money-account split would require inventing an allocation the books do
 * not contain. The money accounts ride along as labels, never as filters.
 */
final class CashAndBankRow
{
    /**
     * @param  Collection<int, \App\Modules\Foundation\Models\MoneyAccount>  $moneyAccounts
     */
    public function __construct(
        public readonly Account $account,
        public readonly Collection $moneyAccounts,
        public readonly Money $opening,
        public readonly Money $debit,
        public readonly Money $credit,
    ) {}

    /**
     * ⚠ `credit` is already negative, exactly as on the trial balance row this
     * figure came from. Adding it is the subtraction.
     */
    public function closing(): Money
    {
        return $this->opening->plus($this->debit)->plus($this->credit);
    }

    /**
     * ⚠ C-CBB-3. A deactivated cash box with a residual balance must stay
     * visible - hiding the row hides money. A deactivated box that never saw a
     * posting should not haunt the report.
     */
    public function isWorthShowing(): bool
    {
        return $this->moneyAccounts->contains(fn ($moneyAccount) => $moneyAccount->is_active)
            || ! $this->opening->isZero()
            || ! $this->debit->isZero()
            || ! $this->credit->isZero()
            || ! $this->closing()->isZero();
    }
}
