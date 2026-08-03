<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Models\Account;

/**
 * One account's line on the trial balance.
 *
 * ⚠ Everything here is DERIVED from the three stored figures. Nothing is
 * recomputed from anywhere else, so a row cannot disagree with itself.
 */
final class TrialBalanceRow
{
    public function __construct(
        public readonly Account $account,
        public readonly Money $opening,
        public readonly Money $debit,
        public readonly Money $credit,
    ) {}

    /**
     * ⚠ `credit` is already negative, because the ledger stores one signed
     * amount. Adding it is the subtraction; writing a minus here would double it.
     */
    public function closing(): Money
    {
        return $this->opening->plus($this->debit)->plus($this->credit);
    }

    public function hasActivity(): bool
    {
        return ! $this->debit->isZero() || ! $this->credit->isZero();
    }

    /**
     * ⚠ An account with no opening balance AND no movement is hidden. A chart of
     * fifty-nine rows of zeroes is a report nobody reads, and the accounts that
     * matter are the ones something happened to.
     */
    public function isWorthShowing(): bool
    {
        return $this->hasActivity() || ! $this->opening->isZero() || ! $this->closing()->isZero();
    }
}
