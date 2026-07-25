<?php

namespace App\Modules\Hrm\Payroll\Stages;

use App\Modules\Hrm\Payroll\Contracts\PayrollStage;
use App\Modules\Hrm\Payroll\Data\PayrollComputation;
use App\Modules\Hrm\Payroll\Data\PayslipLineData;
use App\Modules\Hrm\Salary\ResolvedSalaryLine;

/**
 * Stage 1 — lay the contractual salary onto the payslip (SAL-01 / Correction C3).
 *
 * Phase 3a already resolved the structure to money and attached a formula to
 * every line, so this copies rather than recalculates: the same figures HR sees
 * on the salary screen are the ones the payslip starts from, and there is no
 * second implementation of the component maths to drift.
 */
class ResolveSalaryLines implements PayrollStage
{
    public function handle(PayrollComputation $computation): PayrollComputation
    {
        $context = $computation->context;

        $computation = $computation->note($context->period->describe());

        if ($context->salary->lines === []) {
            return $computation->block('No salary structure is in force for this employee in this period.');
        }

        foreach ($context->salary->lines as $line) {
            $computation = $computation->withLine($this->toPayslipLine($line));
        }

        return $computation->note(sprintf(
            'Contractual gross %s from the salary structure.',
            number_format($context->salary->gross, 2),
        ));
    }

    private function toPayslipLine(ResolvedSalaryLine $line): PayslipLineData
    {
        return new PayslipLineData(
            code: $line->code,
            label: $line->name,
            type: $line->type,
            amount: $line->amount,
            quantity: null,
            rate: null,
            formula: $line->formula,
            source: 'salary_structure',
        );
    }
}
