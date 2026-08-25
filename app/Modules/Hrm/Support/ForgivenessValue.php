<?php

namespace App\Modules\Hrm\Support;

use App\Modules\Hrm\Models\AttendanceWaiver;
use App\Modules\Hrm\Models\SalaryStructure;
use App\Modules\Hrm\Payroll\Data\PayrollPeriodSpec;
use App\Modules\Hrm\Payroll\PayrollConfig;
use Illuminate\Support\Carbon;

/**
 * What one act of forgiveness cost the company.
 *
 * ⚠⚠ THE PRICE IS NOT RE-DERIVED HERE. A waived day is worth exactly what
 * payroll would have deducted for it, so this asks `PayrollConfig` the same
 * question payroll asks: gross (or basic, per the config) divided by the
 * period's divisor days. That rule's own docblock says it must have exactly one
 * home, and warns what a second copy costs - two places computing one rule
 * eventually disagree about the same person, with no error raised, just two
 * different numbers. On a forgiveness register that disagreement would be a
 * figure the owner is told the company gave away.
 *
 * ⚠ Priced at the salary IN FORCE ON THE WAIVED DAY, not today's. Forgiving a
 * day in March and reading the register in December must give the same answer
 * both times, and a raise in between must not rewrite what was forgiven.
 *
 * ⚠⚠ A waiver belongs to the PAYROLL month containing its date, not the
 * calendar month. With the 24-to-23 cycle, forgiveness granted on 26 August
 * lands in the payroll month named September, because September's payroll is
 * where the money would have been taken.
 */
class ForgivenessValue
{
    /**
     * @return array{year:int, month:int, label:string, amount:float, daily_rate:float}
     */
    public static function for(AttendanceWaiver $waiver): array
    {
        $date = Carbon::parse($waiver->attendance_date);
        $spec = PayrollPeriodSpec::containing($date, PayrollCycle::startDay());

        $structure = SalaryStructure::activeFor($waiver->employee_id, $date);
        $breakdown = $structure?->breakdown();

        $config = PayrollConfig::defaults(divisorDays: $spec->divisorDays);

        // ⚠ No salary structure on that date means no deduction could have been
        // priced either, so the honest value is zero rather than a guess from
        // whatever the person earns now.
        $rate = $breakdown === null
            ? 0.0
            : $config->dailyRateFrom((float) $breakdown->basic, (float) $breakdown->gross);

        return [
            'year' => $spec->year,
            'month' => $spec->month,
            'label' => $spec->label(),
            // One waiver forgives one day: the payroll context drops exactly
            // one day from absentDays or deductibleDays for it.
            'amount' => round($rate, 2),
            'daily_rate' => round($rate, 2),
        ];
    }
}
