<?php

namespace App\Modules\Hrm\Payroll\Stages;

use App\Modules\Hrm\Enums\SalaryComponentType;
use App\Modules\Hrm\Payroll\Contracts\PayrollStage;
use App\Modules\Hrm\Payroll\Data\PayrollComputation;
use App\Modules\Hrm\Payroll\Data\PayslipLineData;

/**
 * Stage 3 — absence and unpaid leave (PAY-03, PAY-04, PAY-08).
 *
 * PAY-03 deducts absence from **Gross**, not Basic, at PAY-02's fixed 30-day
 * rate. PAY-04 ticked "same formula as absent" for unpaid leave, so the two
 * share one calculation and differ only in the line they land on — which keeps
 * a payslip readable without pretending they are the same thing.
 *
 * Paid leave is conspicuously absent from this stage: PAY-08 says it costs
 * nothing, so it produces no line at all rather than a zero one.
 */
class ApplyAbsenceDeductions implements PayrollStage
{
    public function handle(PayrollComputation $computation): PayrollComputation
    {
        $context = $computation->context;
        $facts = $context->attendance;

        $computation = $this->deduct($computation, 'absent', 'Absent Deduction', $facts->absentDays, 'attendance');
        $computation = $this->deduct($computation, 'lwp', 'Leave Without Pay', $facts->unpaidLeaveDays, 'leave');

        if ($facts->paidLeaveDays > 0) {
            $computation = $computation->note(sprintf(
                '%s day%s of paid leave count as worked and are not deducted.',
                self::trim($facts->paidLeaveDays),
                $facts->paidLeaveDays == 1.0 ? '' : 's',
            ));
        }

        return $computation;
    }

    private function deduct(PayrollComputation $computation, string $code, string $label, float $days, string $source): PayrollComputation
    {
        if ($days <= 0) {
            return $computation;
        }

        $context = $computation->context;
        $rate = $context->dailyRate();
        $amount = round($rate * $days, 2);

        return $computation->withLine(new PayslipLineData(
            code: $code,
            label: $label,
            type: SalaryComponentType::Deduction,
            amount: $amount,
            quantity: $days,
            rate: round($rate, 4),
            formula: sprintf(
                '%s ÷ %d × %s day%s',
                number_format($context->dailyRateBaseAmount(), 2),
                $context->config->divisorDays,
                self::trim($days),
                $days == 1.0 ? '' : 's',
            ),
            source: $source,
        ));
    }

    private static function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2), '0'), '.');
    }
}
