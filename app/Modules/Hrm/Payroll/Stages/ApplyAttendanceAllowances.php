<?php

namespace App\Modules\Hrm\Payroll\Stages;

use App\Modules\Hrm\Payroll\Contracts\PayrollStage;
use App\Modules\Hrm\Payroll\Data\PayrollComputation;
use App\Modules\Hrm\Payroll\Data\PayslipLineData;
use App\Modules\Hrm\Salary\ResolvedSalaryLine;

/**
 * Stage 5 — allowances earned per day reported for work (Correction C14).
 *
 * Bor De Guna's two Retail Sales Officers receive a travel and daily allowance
 * for each day they turn up: 246.00 and 361.00 respectively. That is genuinely
 * part of what they are paid, so it lives on the salary structure as an
 * effective-dated component like any other, rather than as a monthly ad-hoc
 * input somebody has to remember to enter.
 *
 * But it cannot be resolved where the rest of the structure is resolved.
 * `SalaryResolver` answers "what is this person paid" with no payroll period in
 * scope, and a per-day allowance has no answer until a period names the days.
 * So the structure carries the *rate* on the line's `value` and resolves the
 * amount to 0.00, and the multiplication happens here, where the context
 * carries `AttendanceFacts`.
 *
 * ⚠⚠ **TWO PROPERTIES THIS STAGE DEPENDS ON. Breaking either one silently
 * changes what people are paid, and the payslip still foots.**
 *
 * **1. The allowance is not in Gross**, and `SalaryResolver` is where that is
 * enforced. `EmployeePayrollContext::dailyRate()` divides Gross by 30 to price
 * absence (PAY-03) and part-period proration (PAY-05/06), so an allowance
 * inside Gross would raise the cost of every absent day for every other
 * component. It is also why this stage does not need to exempt itself from
 * proration: there is nothing to exempt, because the allowance was never in the
 * figure proration is computed from.
 *
 * **2. It runs after `ApplyProration`, `ApplyAbsenceDeductions` and
 * `ApplyAttendanceConsequences`.** Position is the second half of the same
 * guarantee: by the time the allowance exists as a payslip line, everything
 * that could have prorated or deducted against it has already run. An absent
 * day therefore costs one day's salary and one day's allowance-that-was-never-
 * earned, which is the correct single penalty, not the double one the
 * production-data review warned about.
 *
 * The eligibility rule itself is not this stage's business. Attendance decides
 * who reported for work (`DailyAttendance::reportedForWork()`), Payroll prices
 * it — the same boundary the whole payroll architecture rests on.
 */
class ApplyAttendanceAllowances implements PayrollStage
{
    public function handle(PayrollComputation $computation): PayrollComputation
    {
        $context = $computation->context;
        $days = $context->attendance->daysReportedForWork;

        foreach ($context->salary->lines as $line) {
            if (! $line->isPricedByAttendance()) {
                continue;
            }

            $computation = $this->apply($computation, $line, $days);
        }

        return $computation;
    }

    private function apply(PayrollComputation $computation, ResolvedSalaryLine $line, int $days): PayrollComputation
    {
        $rate = $line->value;

        // No days reported means no allowance. A zero line is deliberately
        // still emitted: this is money the employee normally receives, and its
        // visible absence on the payslip is the explanation for a smaller net.
        // A missing line just looks like the allowance was forgotten.
        $amount = round($rate * $days, 2);

        return $computation
            ->withLine(new PayslipLineData(
                code: $line->code,
                label: $line->name,
                type: $line->type,
                amount: $amount,
                quantity: (float) $days,
                rate: round($rate, 4),
                formula: sprintf(
                    '%s × %d day%s reported for work',
                    number_format($rate, 2),
                    $days,
                    $days === 1 ? '' : 's',
                ),
                source: 'salary_structure',
            ))
            ->note(sprintf(
                '%s paid for %d day%s reported for work. Not part of gross, so it does not affect absence, proration or overtime.',
                $line->name,
                $days,
                $days === 1 ? '' : 's',
            ));
    }
}
