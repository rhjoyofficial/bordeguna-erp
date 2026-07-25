<?php

namespace App\Modules\Hrm\Payroll\Stages;

use App\Modules\Hrm\Enums\SalaryComponentType;
use App\Modules\Hrm\Payroll\Contracts\PayrollStage;
use App\Modules\Hrm\Payroll\Data\PayrollComputation;
use App\Modules\Hrm\Payroll\Data\PayslipLineData;

/**
 * Stage 4 — price the attendance consequence (PAY-07, POL-02, POL-03).
 *
 * This stage decides nothing. POL-03's occurrence ladder already ran in the
 * attendance engine, which emitted a consequence as a *fact* about each day;
 * all that happens here is turning "one day's pay is forfeit" into an amount,
 * because only payroll knows what a day is worth.
 *
 * PAY-13 is the case that proves the separation matters: four late arrivals
 * produce a Late status and a verbal warning, and no money moves at all. A
 * design that priced lateness directly from minutes would have charged for it.
 */
class ApplyAttendanceConsequences implements PayrollStage
{
    public function handle(PayrollComputation $computation): PayrollComputation
    {
        $context = $computation->context;
        $facts = $context->attendance;

        if ($facts->lateOccurrences > 0 && $facts->deductibleDays <= 0) {
            return $computation->note(sprintf(
                '%d late arrival%s (%d minutes) carried no salary consequence under the attendance policy.',
                $facts->lateOccurrences,
                $facts->lateOccurrences === 1 ? '' : 's',
                $facts->lateMinutes,
            ));
        }

        if ($facts->deductibleDays <= 0) {
            return $computation;
        }

        $rate = $context->dailyRate();
        $days = $facts->deductibleDays;

        return $computation
            ->withLine(new PayslipLineData(
                code: 'late_early',
                label: 'Late / Early Deduction',
                type: SalaryComponentType::Deduction,
                amount: round($rate * $days, 2),
                quantity: $days,
                rate: round($rate, 4),
                formula: sprintf(
                    '%s ÷ %d × %s day%s forfeited by the attendance policy',
                    number_format($context->dailyRateBaseAmount(), 2),
                    $context->config->divisorDays,
                    rtrim(rtrim(number_format($days, 2), '0'), '.'),
                    $days == 1.0 ? '' : 's',
                ),
                source: 'attendance_policy',
            ))
            ->note(sprintf(
                'The attendance policy forfeited %s day%s of pay across %d late arrival%s.',
                rtrim(rtrim(number_format($days, 2), '0'), '.'),
                $days == 1.0 ? '' : 's',
                $facts->lateOccurrences,
                $facts->lateOccurrences === 1 ? '' : 's',
            ));
    }
}
