<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Foundation\Models\MoneyAccount;
use Illuminate\Database\Eloquent\Model;

/**
 * One proposed line: an account, an amount, and optionally who it is about.
 *
 * ⚠ `debit()` and `credit()` exist so a caller never has to remember which way
 * the sign runs. Both are the same field; only the sign differs, and a named
 * constructor is cheaper to read than a minus sign somebody has to trust.
 */
final class JournalLineDraft
{
    private function __construct(
        public readonly int $accountId,
        public readonly Money $amount,
        public readonly ?string $memo = null,
        public readonly ?Model $party = null,
        public readonly ?MoneyAccount $moneyAccount = null,
    ) {}

    public static function debit(int $accountId, Money $amount, ?string $memo = null, ?Model $party = null, ?MoneyAccount $moneyAccount = null): self
    {
        return new self($accountId, $amount->absolute(), $memo, $party, $moneyAccount);
    }

    public static function credit(int $accountId, Money $amount, ?string $memo = null, ?Model $party = null, ?MoneyAccount $moneyAccount = null): self
    {
        return new self($accountId, $amount->absolute()->negated(), $memo, $party, $moneyAccount);
    }

    /**
     * For a caller that already has a signed amount - the reversal, mainly,
     * which negates whatever the original line was without needing to know
     * which side it sat on.
     */
    public static function signed(int $accountId, Money $amount, ?string $memo = null, ?Model $party = null, ?MoneyAccount $moneyAccount = null): self
    {
        return new self($accountId, $amount, $memo, $party, $moneyAccount);
    }
}
