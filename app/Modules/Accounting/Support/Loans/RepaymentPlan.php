<?php

namespace App\Modules\Accounting\Support\Loans;

use App\Modules\Accounting\Enums\LoanWaterfall;
use App\Modules\Accounting\Models\LoanAllocation;
use App\Modules\Accounting\Models\LoanContract;
use App\Modules\Accounting\Models\LoanEntry;
use Illuminate\Support\Carbon;

/**
 * How a repayment splits between interest and principal, and which
 * installments it settles (loans plan v2, phase 2; ChatGPT confirmation,
 * point 7).
 *
 * ⚠ The split follows the loan's own WATERFALL:
 *
 *   interest first    unpaid interest due by the day, then principal (due first,
 *                     then the rest of the loan as a prepayment)
 *   principal first   unpaid principal due by the day, then interest due, then
 *                     the rest of the loan as a prepayment
 *
 * Anything beyond the whole principal is interest beyond the schedule (the
 * broken-period interest on an early settlement), capped at what could be
 * owed. The person recording it may adjust the proposed split; the cash is
 * what the bank statement says.
 *
 * ⚠ TDS is computed on the GROSS interest settled (TdsPolicy), never on the
 * net cash.
 */
final class RepaymentPlan
{
    /**
     * @return array{interest: int, principal: int}
     */
    public static function propose(LoanContract $loan, LoanPosition $position, int $amountMinor, Carbon $on): array
    {
        $amount = max(0, $amountMinor);
        $dueInterest = $position->dueInterest($on);
        $duePrincipal = min($position->duePrincipal($on), max(0, $position->principal));
        $outstanding = max(0, $position->principal);

        if ($loan->waterfall === LoanWaterfall::PrincipalFirst) {
            $principal = min($amount, $duePrincipal);
            $rest = $amount - $principal;
            $interest = min($rest, $dueInterest);
            $rest -= $interest;
            $more = min($rest, $outstanding - $principal);
            $principal += $more;
            $rest -= $more;
        } else {
            $interest = min($amount, $dueInterest);
            $rest = $amount - $interest;
            $principal = min($rest, $outstanding);
            $rest -= $principal;
        }

        return ['interest' => $interest + $rest, 'principal' => $principal];
    }

    /** The most interest a repayment may carry: all scheduled interest unpaid, plus anything accrued. */
    public static function maxInterest(LoanPosition $position): int
    {
        return $position->unpaidScheduledInterest() + max(0, $position->accrued);
    }

    /**
     * Settles current installments in due order: the interest part against
     * unpaid interest, the principal part against unpaid principal. A part
     * larger than the schedule holds stays on the entry, unallocated.
     *
     * @param  Carbon|null  $forgoFrom  for a waiver: settle rows due after this day first
     */
    public static function allocate(LoanEntry $entry, LoanPosition $position, int $principalMinor, int $interestMinor, ?Carbon $forgoFrom = null): void
    {
        $rows = $position->currentRows();

        if ($forgoFrom !== null) {
            usort($rows, fn (array $a, array $b) => [$a['due_date']->lessThanOrEqualTo($forgoFrom) ? 1 : 0, $a['sequence']] <=> [$b['due_date']->lessThanOrEqualTo($forgoFrom) ? 1 : 0, $b['sequence']]);
        }

        $leftPrincipal = max(0, $principalMinor);
        $leftInterest = max(0, $interestMinor);
        $now = now();

        foreach ($rows as $row) {
            if ($leftPrincipal === 0 && $leftInterest === 0) {
                break;
            }

            $interest = min($leftInterest, $row['interest_unpaid']);
            $principal = min($leftPrincipal, $row['principal_unpaid']);

            if ($interest === 0 && $principal === 0) {
                continue;
            }

            LoanAllocation::query()->create([
                'loan_entry_id' => $entry->id,
                'loan_schedule_row_id' => $row['id'],
                'principal_minor' => $principal,
                'interest_minor' => $interest,
                'created_at' => $now,
            ]);

            $leftInterest -= $interest;
            $leftPrincipal -= $principal;
        }
    }
}
