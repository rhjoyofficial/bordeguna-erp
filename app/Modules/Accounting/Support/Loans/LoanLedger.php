<?php

namespace App\Modules\Accounting\Support\Loans;

use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Models\LoanContract;
use Illuminate\Support\Carbon;

/**
 * What the GENERAL LEDGER says about each register loan: the lines that carry
 * its id (journal_lines.loan_contract_id), head by head.
 *
 * ⚠ Read from the ledger, never from the register, so the reconciliation can
 * hold one against the other (LoanReconciliation).
 */
final class LoanLedger
{
    /**
     * loan id => account id => signed paisa (debits positive).
     *
     * @param  list<int>|null  $loanIds  null for every loan of this business
     * @return array<int, array<int, int>>
     */
    public static function balances(?array $loanIds = null, ?Carbon $asAt = null): array
    {
        if ($loanIds === []) {
            return [];
        }

        $rows = JournalLine::query()
            ->whereNotNull('journal_lines.loan_contract_id')
            ->when($loanIds !== null, fn ($q) => $q->whereIn('journal_lines.loan_contract_id', $loanIds))
            ->when($asAt !== null, fn ($q) => $q
                ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
                ->where('journals.journal_date', '<=', $asAt->toDateString()))
            ->groupBy('journal_lines.loan_contract_id', 'journal_lines.account_id')
            ->selectRaw('journal_lines.loan_contract_id as loan_id, journal_lines.account_id as account_id, SUM(journal_lines.amount_minor) as total_minor')
            ->toBase()
            ->get();

        $balances = [];

        foreach ($rows as $row) {
            $balances[(int) $row->loan_id][(int) $row->account_id] = (int) $row->total_minor;
        }

        return $balances;
    }

    /** What the ledger holds for this loan on one head, as an amount owed (given: debit; taken: credit). */
    public static function owedOn(LoanContract $loan, ?int $accountId, ?Carbon $asAt = null): int
    {
        if ($accountId === null) {
            return 0;
        }

        $signed = self::balances([$loan->id], $asAt)[$loan->id][$accountId] ?? 0;

        return $signed * $loan->direction->sign();
    }

    /** Principal the ledger holds for this loan: its principal head plus its current portion. */
    public static function principalOwed(LoanContract $loan, ?Carbon $asAt = null): int
    {
        $heads = self::balances([$loan->id], $asAt)[$loan->id] ?? [];
        $total = 0;

        foreach ($loan->principalHeadIds() as $id) {
            $total += $heads[$id] ?? 0;
        }

        return $total * $loan->direction->sign();
    }

    public static function accruedOwed(LoanContract $loan, ?Carbon $asAt = null): int
    {
        return self::owedOn($loan, (int) $loan->interest_accrual_account_id, $asAt);
    }
}
