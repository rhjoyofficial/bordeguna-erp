<?php

namespace App\Modules\Purchasing\Support;

use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Support\Money;
use App\Modules\Purchasing\Models\Supplier;
use Illuminate\Support\Carbon;

/**
 * What a supplier owes US on Accounts Payable (ruling P5, 19 Oct 2026): the
 * DEBIT balance of 2110 with the supplier as party, read from the ledger,
 * because the ledger is the truth the refund has to agree with.
 *
 * Zero when the supplier is owed (a credit balance) or square. Advances are
 * not here: they sit in 1160 and are refunded as advances (SARF).
 */
final class SupplierCreditBalance
{
    public static function of(Supplier $supplier, ?Carbon $asAt = null): Money
    {
        $on = ($asAt ?? Carbon::today())->copy()->startOfDay();
        $payable = AccountMapping::forKeyOn('purchasing.accounts_payable', $on)?->account;

        if ($payable === null) {
            return Money::zero();
        }

        $balance = (int) JournalLine::query()
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journal_lines.account_id', $payable->id)
            ->where('journal_lines.party_type', $supplier->getMorphClass())
            ->where('journal_lines.party_id', $supplier->id)
            ->whereDate('journals.journal_date', '<=', $on->toDateString())
            ->sum('journal_lines.amount_minor');

        // Debits are positive: a positive balance on a payable is owed to us.
        return Money::ofMinor(max(0, $balance));
    }
}
