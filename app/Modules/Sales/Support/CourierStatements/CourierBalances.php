<?php

namespace App\Modules\Sales\Support\CourierStatements;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Models\MoneyAccount;
use Illuminate\Support\Facades\DB;

/**
 * One courier's balances in this business's books, read from the ledger.
 *
 * ⭐ Each courier is its own because every line on 1145, 2165 and 4960 names
 * the courier's money account (Joy, 11 Oct 2026). Summing the head alone would
 * answer "all couriers together" for each one of them.
 */
final class CourierBalances
{
    /** What the courier holds for us: the 1145 lines naming it. Positive = owed to us. */
    public static function receivable(MoneyAccount $courier): Money
    {
        return self::sum($courier->account_id === null ? null : (int) $courier->account_id, $courier);
    }

    /** What we owe the courier on 2165. Positive = owed to it. */
    public static function payable(MoneyAccount $courier): Money
    {
        return self::sum(Account::bySlug('courier_payable')?->id, $courier)->negated();
    }

    /** Compensation the courier has paid us, on 4960. Positive = income. */
    public static function compensation(MoneyAccount $courier): Money
    {
        return self::sum(Account::bySlug('courier_compensation_income')?->id, $courier)->negated();
    }

    private static function sum(?int $accountId, MoneyAccount $courier): Money
    {
        if ($accountId === null) {
            return Money::zero();
        }

        return Money::ofMinor((int) DB::table('journal_lines')
            ->where('business_id', $courier->business_id)
            ->where('account_id', $accountId)
            ->where('money_account_id', $courier->id)
            ->sum('amount_minor'));
    }
}
