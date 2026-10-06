<?php

namespace App\Modules\Hrm\Payroll\Stages;

use App\Modules\Hrm\Enums\SalaryComponentType;
use App\Modules\Hrm\Payroll\Contracts\PayrollStage;
use App\Modules\Hrm\Payroll\Data\PayrollComputation;
use App\Modules\Hrm\Payroll\Data\PayslipLineData;

/**
 * Stage 7b - the staff purchase recovery, capped (Finance policy B8, 29 Sep
 * 2026): "Staff purchase recovery capped at 10% of net pay a month, rest rolls
 * forward."
 *
 * ⚠ It runs AFTER every other line and BEFORE validation, because the cap is a
 * share of the net, and the net it is a share of is the net before this
 * recovery. Taking the share of the net after it would make the deduction
 * depend on itself.
 *
 * Nothing is written to roll the rest forward. What is owed is derived each
 * month (charged so far minus recovered so far, see PayrollContextBuilder), so
 * whatever this month does not take is simply still owed next month, and a
 * re-run of an open payroll reproduces the same figure.
 */
class ApplyStaffPurchaseRecovery implements PayrollStage
{
    public const CODE = 'staff_purchase';

    public function handle(PayrollComputation $computation): PayrollComputation
    {
        $context = $computation->context;
        $owed = round($context->staffPurchaseOwed, 2);

        if ($owed <= 0) {
            return $computation;
        }

        $percent = $context->staffPurchaseCapPercent;
        $netBefore = round($computation->totalEarnings() - $computation->totalDeductions(), 2);

        if ($percent === null) {
            $take = $owed;
            $formula = sprintf('Staff purchase recovery %s (no cap set).', number_format($owed, 2));
        } else {
            $cap = max(0.0, round($netBefore * $percent / 100, 2));
            $take = min($owed, $cap);
            $rest = round($owed - $take, 2);
            $formula = sprintf(
                '%d%% of net pay %s = %s cap; %s owed%s.',
                $percent,
                number_format($netBefore, 2),
                number_format($cap, 2),
                number_format($owed, 2),
                $rest > 0 ? sprintf('; %s rolls forward to next month', number_format($rest, 2)) : '',
            );
        }

        if ($take <= 0) {
            return $computation->note($formula);
        }

        return $computation->withLine(new PayslipLineData(
            code: self::CODE,
            label: 'Staff purchase recovery',
            type: SalaryComponentType::Deduction,
            amount: round($take, 2),
            quantity: null,
            rate: null,
            formula: $formula,
            source: 'staff_purchase',
        ));
    }
}
