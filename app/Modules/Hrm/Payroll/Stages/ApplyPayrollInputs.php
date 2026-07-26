<?php

namespace App\Modules\Hrm\Payroll\Stages;

use App\Modules\Hrm\Payroll\Contracts\PayrollStage;
use App\Modules\Hrm\Payroll\Data\PayrollComputation;
use App\Modules\Hrm\Payroll\Data\PayrollInput;
use App\Modules\Hrm\Payroll\Data\PayslipLineData;

/**
 * Stage 6 — allowances and bonuses (ADJ-05), loan installments (ADJ-07) and
 * adjustments or arrears (ADJ-08).
 *
 * These arrive already decided: an approved bonus, a due installment, an
 * authorised adjustment. Payroll's part is to place each on the right side of
 * the payslip with its own line, never to net them together — ADJ-08 lists six
 * distinct adjustment types precisely because "one adjustment of −3,200" tells
 * nobody anything.
 *
 * The records behind these come in Phases 3d and 3e. The seam exists now so
 * that filling it does not mean reopening the engine, exactly as `DayContext`
 * carried its leave and movement flags before Phase 2c built them.
 */
class ApplyPayrollInputs implements PayrollStage
{
    public function handle(PayrollComputation $computation): PayrollComputation
    {
        $context = $computation->context;

        foreach ([...$context->allowances, ...$context->loanInstallments, ...$context->adjustments] as $input) {
            $computation = $computation->withLine($this->toLine($input));
        }

        return $computation;
    }

    private function toLine(PayrollInput $input): PayslipLineData
    {
        return new PayslipLineData(
            code: $input->code,
            label: $input->label,
            type: $input->type,
            amount: round($input->amount, 2),
            // Carried through rather than dropped: a loan installment is one of
            // a counted series, and PAY-13 asks for the quantity and rate behind
            // an amount. A bonus or adjustment supplies neither and stays null.
            quantity: $input->quantity,
            rate: $input->rate,
            formula: $input->note ?? number_format($input->amount, 2),
            source: $input->source,
        );
    }
}
