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
 * PAY-11's carry-forward balance was settled by Correction C8c, and deliberately
 * **not** by adding a rule here. The block stays exactly as it was: this stage
 * still refuses a negative net and still hands the decision to a human. What
 * changed is what a human can then decide — carrying the shortfall forward posts
 * a balance-ledger entry, and the resulting credit arrives on the next
 * calculation as an ordinary input, like every other money movement payroll does
 * not itself author. The engine gained no knowledge of balances.
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
