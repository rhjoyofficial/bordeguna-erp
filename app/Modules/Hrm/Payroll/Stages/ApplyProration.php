<?php

namespace App\Modules\Hrm\Payroll\Stages;

use App\Modules\Hrm\Enums\SalaryComponentType;
use App\Modules\Hrm\Payroll\Contracts\PayrollStage;
use App\Modules\Hrm\Payroll\Data\PayrollComputation;
use App\Modules\Hrm\Payroll\Data\PayslipLineData;

/**
 * Stage 2 — a part-period joiner or leaver (PAY-05, PAY-06).
 *
 * PAY-05 ticked calendar-day proration from the joining date, and PAY-06 pays a
 * leaver up to their last working date. Both are expressed here as a deduction
 * for the days the person was not on the books, so the payslip still shows the
 * full contractual gross with a visible, explained reduction beneath it —
 * rather than a silently smaller salary line nobody can reconcile against the
 * contract.
 *
 * Runs before the absence stages so that a mid-month joiner is never charged
 * against days they had not joined for.
 */
class ApplyProration implements PayrollStage
{
    public function handle(PayrollComputation $computation): PayrollComputation
    {
        $context = $computation->context;

        if (! $context->isPartialPeriod()) {
            return $computation;
        }

        $employed = $context->period->employedDays($context->joining(), $context->separation());
        // Rule #23a (14 Oct 2026): the 30-day divisor for joiners and leavers,
        // short months included, is kept UNCHANGED on purpose. It waits on the
        // accountant (and a labour-law check) to confirm it; do not change it
        // until that answer is written down.
        $divisor = $context->config->divisorDays;
        $unpaidDays = max(0, $divisor - $employed);

        if ($unpaidDays <= 0) {
            return $computation;
        }

        $rate = $context->dailyRate();
        $amount = round($rate * $unpaidDays, 2);

        $reason = $context->joining() !== null && $context->joining()->gt($context->period->start)
            ? sprintf('joined %s', $context->joining()->format('j F'))
            : sprintf('left %s', $context->separation()?->format('j F'));

        return $computation
            ->withLine(new PayslipLineData(
                code: 'proration',
                label: 'Part-period adjustment',
                type: SalaryComponentType::Deduction,
                amount: $amount,
                quantity: (float) $unpaidDays,
                rate: round($rate, 4),
                formula: sprintf(
                    '%s ÷ %d × %d day%s not employed',
                    number_format($context->dailyRateBaseAmount(), 2),
                    $divisor,
                    $unpaidDays,
                    $unpaidDays === 1 ? '' : 's',
                ),
                source: 'employment_dates',
            ))
            ->note(sprintf(
                'Employed %d of %d days (%s), so %d day%s are not paid.',
                $employed, $divisor, $reason, $unpaidDays, $unpaidDays === 1 ? '' : 's',
            ));
    }
}
