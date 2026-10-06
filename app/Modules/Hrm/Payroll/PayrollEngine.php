<?php

namespace App\Modules\Hrm\Payroll;

use App\Modules\Hrm\Payroll\Contracts\PayrollStage;
use App\Modules\Hrm\Payroll\Data\EmployeePayrollContext;
use App\Modules\Hrm\Payroll\Data\PayrollComputation;
use App\Modules\Hrm\Payroll\Data\PayslipOutcome;
use App\Modules\Hrm\Payroll\Stages\ApplyAbsenceDeductions;
use App\Modules\Hrm\Payroll\Stages\ApplyAttendanceAllowances;
use App\Modules\Hrm\Payroll\Stages\ApplyAttendanceConsequences;
use App\Modules\Hrm\Payroll\Stages\ApplyOvertime;
use App\Modules\Hrm\Payroll\Stages\ApplyPayrollInputs;
use App\Modules\Hrm\Payroll\Stages\ApplyProration;
use App\Modules\Hrm\Payroll\Stages\ApplyStaffPurchaseRecovery;
use App\Modules\Hrm\Payroll\Stages\ResolveSalaryLines;
use App\Modules\Hrm\Payroll\Stages\ValidatePayslip;

/**
 * Turns one immutable employee-period into one payslip.
 *
 * Pure: no database, no clock, no configuration of its own. Everything arrives
 * on the EmployeePayrollContext — which is also PAY-12's cutoff snapshot — and
 * everything it decides is derived here in a fixed order:
 *
 *   1. lay down the contractual salary          (SAL-01 / C3)
 *   2. prorate a part period                    (PAY-05, PAY-06)
 *   3. deduct absence and unpaid leave          (PAY-03, PAY-04, PAY-08)
 *   4. price the attendance consequence         (PAY-07, POL-02/03)
 *   5. add per-day attendance allowances        (C14 — TA & DA)
 *   6. add overtime                             (ADJ-01, ADJ-02 via C7a, ADJ-03)
 *   7. add allowances, loans and adjustments    (ADJ-05, ADJ-07, ADJ-08)
 *   7b. recover staff purchases, capped          (Finance policy B8)
 *   8. run the blocking checks                  (PAY-09, PAY-11)
 *
 * The order is not arbitrary. Gross must exist before anything is deducted from
 * it; proration precedes the absence maths so a mid-month joiner is never
 * charged for days before they arrived; and validation runs last because both
 * of its questions need the finished figures.
 *
 * ⚠ Stage 5 sits after every deduction for the same reason: a per-day allowance
 * is earned by turning up, so it must never be prorated or deducted against.
 * See `ApplyAttendanceAllowances` and `TA-DA-ARCHITECTURE-PLAN.md`.
 *
 * Because of that, a payslip can be recalculated at any time and reproduce
 * exactly the same answer from the same inputs — which is what makes a payroll
 * figure defensible to the person whose pay it is, and what lets the entire
 * rule set be tested from fixtures with no database at all.
 *
 * The engine exists to reproduce the client's approved PAY-13 worked example;
 * that acceptance test was written before any of this code.
 */
class PayrollEngine
{
    /** @var array<int, PayrollStage> */
    private array $stages;

    /**
     * @param  array<int, PayrollStage>|null  $stages
     */
    public function __construct(?array $stages = null)
    {
        $this->stages = $stages ?? [
            new ResolveSalaryLines,
            new ApplyProration,
            new ApplyAbsenceDeductions,
            new ApplyAttendanceConsequences,
            new ApplyAttendanceAllowances,
            new ApplyOvertime,
            new ApplyPayrollInputs,
            new ApplyStaffPurchaseRecovery,
            new ValidatePayslip,
        ];
    }

    public function process(EmployeePayrollContext $context): PayslipOutcome
    {
        $computation = array_reduce(
            $this->stages,
            fn (PayrollComputation $carry, PayrollStage $stage) => $stage->handle($carry),
            PayrollComputation::start($context),
        );

        return $this->produce($computation);
    }

    private function produce(PayrollComputation $computation): PayslipOutcome
    {
        $earnings = $computation->totalEarnings();
        $deductions = $computation->totalDeductions();

        return new PayslipOutcome(
            employeeId: $computation->context->employeeId,
            period: $computation->context->period,
            lines: $computation->lines,
            grossSalary: $computation->context->salary->gross,
            totalEarnings: $earnings,
            totalDeductions: $deductions,
            // PAY-10 rounds each component as it is produced, so this is a sum
            // of already-rounded figures rather than a rounded sum — the two
            // differ by a paisa, and the questionnaire asks for the former.
            net: round($earnings - $deductions, 2),
            isBlocked: $computation->blockReasons !== [],
            blockReasons: $computation->blockReasons,
            notes: $computation->notes,
            config: $computation->context->config->toArray(),
        );
    }
}
