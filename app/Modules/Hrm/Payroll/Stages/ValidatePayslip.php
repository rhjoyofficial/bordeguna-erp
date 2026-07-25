<?php

namespace App\Modules\Hrm\Payroll\Stages;

use App\Modules\Hrm\Payroll\Contracts\PayrollStage;
use App\Modules\Hrm\Payroll\Data\PayrollComputation;

/**
 * Stage 7 — the blocking checks (PAY-09, PAY-11).
 *
 * Runs last because both questions need the finished figures. PAY-09 blocks an
 * employee's line while a missing punch is unresolved rather than assuming what
 * the day was worth, and PAY-11 refuses to pay a negative net automatically —
 * both are deliberate stops for a human, not errors.
 *
 * PAY-11's carry-forward balance is **not** implemented here: the questionnaire
 * ticks the block and the manual resolution but never states how a negative
 * balance is recovered, so inventing a recovery schedule would be exactly the
 * kind of assumption this project has avoided. The block is real; the recovery
 * rule stays an open decision.
 */
class ValidatePayslip implements PayrollStage
{
    public function handle(PayrollComputation $computation): PayrollComputation
    {
        $context = $computation->context;

        if ($context->attendance->hasUnresolvedMissingPunch) {
            $computation = $computation->block(
                'An unresolved missing punch is holding this employee\'s attendance. Approve a correction before paying.'
            );
        }

        $net = round($computation->totalEarnings() - $computation->totalDeductions(), 2);

        if ($net < 0) {
            $computation = $computation->block(sprintf(
                'Negative net of %s — deductions exceed earnings. This needs a human decision before approval.',
                number_format($net, 2),
            ));
        }

        return $computation;
    }
}
