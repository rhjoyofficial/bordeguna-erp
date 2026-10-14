<?php

namespace App\Modules\Accounting\Support\Loans;

use App\Modules\Accounting\Enums\LoanDirection;
use App\Modules\Accounting\Models\LoanContract;
use App\Modules\Accounting\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Current and non-current principal (ChatGPT confirmation, point 3): the part
 * of a long-term loan due within 12 months of a reporting date belongs on its
 * current-portion head (1270 -> 11754 on a loan given; 2210, 2220, 2230 ->
 * 2128 on a loan taken), worked out from the CONTRACTUAL schedule.
 *
 * ⚠⚠ PROPOSED, NEVER POSTED. "Prepare reclassification" fills the manual
 * journal screen with the lines, per counterparty, each naming its loan; the
 * Owner reviews and posts it there (accounting.journal.post). The move is the
 * difference between what should be current and what the current-portion head
 * already holds for that loan, so preparing it twice proposes nothing new.
 */
final class LoanReclassification
{
    /**
     * Every approved loan with what it owes, the part due within 12 months,
     * the part after, and (for a long-term loan) what the current-portion head
     * holds and the move it needs.
     *
     * @return list<array{loan: LoanContract, outstanding: int, current: int, non_current: int, held: int, move: int, long_term: bool}>
     */
    public static function rows(Carbon $asAt): array
    {
        $horizon = $asAt->copy()->addMonthsNoOverflow(12);
        $rows = [];

        $loans = LoanContract::query()->with(['counterparty', 'principalAccount', 'currentPortionAccount'])
            ->approvedOnce()
            ->whereDate('start_date', '<=', $asAt->toDateString())
            ->orderBy('loan_number')
            ->get();

        foreach ($loans as $loan) {
            $position = LoanPosition::of($loan, $asAt);
            $outstanding = max(0, $position->principal);

            if ($outstanding === 0) {
                continue;
            }

            $longTerm = $loan->current_portion_account_id !== null;
            $current = $longTerm ? min($outstanding, $position->unpaidPrincipalDueBy($horizon)) : $outstanding;
            $held = $longTerm ? LoanLedger::owedOn($loan, (int) $loan->current_portion_account_id, $asAt) : 0;

            $rows[] = [
                'loan' => $loan,
                'outstanding' => $outstanding,
                'current' => $current,
                'non_current' => $outstanding - $current,
                'held' => $held,
                'move' => $longTerm ? $current - $held : 0,
                'long_term' => $longTerm,
            ];
        }

        return $rows;
    }

    /**
     * The journal the moves need, for the manual journal screen: each line
     * names its counterparty and its loan.
     *
     * @return list<array{account_id: int, debit: string, credit: string, party_id: int, loan_contract_id: int, memo: string}>
     */
    public static function journalLines(Carbon $asAt, ?int $counterpartyId = null): array
    {
        $lines = [];

        foreach (self::rows($asAt) as $row) {
            /** @var LoanContract $loan */
            $loan = $row['loan'];

            if ($row['move'] === 0 || ($counterpartyId !== null && (int) $loan->counterparty_id !== $counterpartyId)) {
                continue;
            }

            $amount = Money::ofMinor(abs($row['move']))->toTaka();
            $memo = "Current portion of {$loan->loan_number} at {$asAt->format('j M Y')}";
            $toCurrent = $row['move'] > 0;

            // Moving principal INTO the current portion: an asset is debited
            // there, a liability credited there.
            $currentIsDebit = ($loan->direction === LoanDirection::Given) === $toCurrent;
            $line = fn (int $accountId, bool $debit) => [
                'account_id' => $accountId,
                'debit' => $debit ? $amount : '',
                'credit' => $debit ? '' : $amount,
                'party_id' => (int) $loan->counterparty_id,
                'loan_contract_id' => $loan->id,
                'memo' => $memo,
            ];

            $lines[] = $line((int) $loan->current_portion_account_id, $currentIsDebit);
            $lines[] = $line((int) $loan->principal_account_id, ! $currentIsDebit);
        }

        return $lines;
    }
}
