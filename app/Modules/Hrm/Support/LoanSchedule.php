<?php

namespace App\Modules\Hrm\Support;

use Illuminate\Support\Carbon;

/**
 * Turns ADJ-07's three terms — principal, installment amount and start month —
 * into a repayment plan.
 *
 * Pure, so the arithmetic can be tested without a database and read without
 * tracing it through an action. Two rules are worth stating because they are
 * where repayment schedules usually go wrong:
 *
 *  - **The last installment carries the remainder.** Twelve payments of 4,166.67
 *    against 50,000 collect 50,000.04, and the reverse case leaves paisa
 *    outstanding forever on a loan everyone considers repaid. The final row is
 *    therefore whatever is actually left, which may be larger or smaller than
 *    the others.
 *  - **The schedule never over-collects.** The sum of the installments equals
 *    the principal exactly. That is asserted in the tests rather than assumed,
 *    because it is the property an employee would notice.
 *
 * No interest and no fees appear anywhere: ADJ-07 lists principal, installment,
 * start month and remaining balance, and nothing else.
 */
final class LoanSchedule
{
    /**
     * @return array<int, array{sequence: int, year: int, month: int, amount: float}>
     */
    public static function build(float $principal, float $installmentAmount, int $startYear, int $startMonth): array
    {
        $principal = round($principal, 2);
        $installmentAmount = round($installmentAmount, 2);

        if ($principal <= 0 || $installmentAmount <= 0) {
            return [];
        }

        $rows = [];
        $remaining = $principal;
        $month = Carbon::create($startYear, $startMonth, 1)->startOfDay();
        $sequence = 1;

        while ($remaining > 0.005) {
            // The last installment is whatever is genuinely left, so the plan
            // adds up to the principal to the paisa.
            $amount = min($installmentAmount, round($remaining, 2));

            $rows[] = [
                'sequence' => $sequence,
                'year' => (int) $month->year,
                'month' => (int) $month->month,
                'amount' => round($amount, 2),
            ];

            $remaining = round($remaining - $amount, 2);
            $month = $month->copy()->addMonth();
            $sequence++;

            // A guard, not a rule: an installment so small it cannot clear the
            // principal would otherwise schedule forever.
            if ($sequence > 600) {
                break;
            }
        }

        return $rows;
    }

    /**
     * How many installments a set of terms implies, for a form that wants to
     * show the consequence before anyone commits to it.
     */
    public static function count(float $principal, float $installmentAmount): int
    {
        if ($principal <= 0 || $installmentAmount <= 0) {
            return 0;
        }

        return (int) ceil(round($principal, 2) / round($installmentAmount, 2));
    }
}
