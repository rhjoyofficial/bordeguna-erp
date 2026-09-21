<?php

namespace App\Modules\Sales\Support;

use App\Modules\Foundation\Models\MoneyAccount;
use RuntimeException;

/**
 * The sentence for money the ledger could not book into the chosen account.
 *
 * ⚠ The posting engine's own words are "No account is mapped to [money account
 * [Till]] on 2026-09-21", which is true and useless to the cashier holding the
 * cash. Almost always the cause is one thing - the account has no ledger head -
 * so that case is said plainly, with what to do next. Anything else (a missing
 * Sales mapping, a head the posting engine refuses) keeps its own message,
 * because only that message names what is actually missing.
 */
final class MoneyAccountRefusal
{
    public static function sentence(MoneyAccount $account, RuntimeException $refusal): string
    {
        if ($account->account_id === null) {
            return "{$account->name} has no ledger account yet, so money cannot be booked into it. "
                .'Pick another account, or finish its setup under Finance & Accounts > Bank & cash.';
        }

        return $refusal->getMessage();
    }
}
