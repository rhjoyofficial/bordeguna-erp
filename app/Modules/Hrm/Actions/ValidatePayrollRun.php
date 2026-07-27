<?php

namespace App\Modules\Hrm\Actions;

use App\Modules\Hrm\Enums\SalaryPaymentMethod;
use App\Modules\Hrm\Models\DailyAttendance;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Models\SalaryStructure;
use App\Modules\Hrm\Support\ScheduleResolver;
use Illuminate\Support\Collection;

/**
 * The pre-calculation checks (WFL-03), run before anything is computed.
 *
 * WFL-03 ticked "all of the above": an active salary structure, a calendar and
 * shift assignment, resolved attendance, approved leave and overtime, and bank
 * or payment details. WFL-09 then says validation errors are shown first and a
 * retry follows once they are fixed — so this deliberately gathers **every**
 * problem across **every** employee rather than stopping at the first.
 *
 * That ordering matters more than it looks. Finding six problems one payroll
 * run at a time is six rounds of frustration; finding all six at once is one.
 */
class ValidatePayrollRun
{
    public function __construct(private readonly ScheduleResolver $resolver) {}

    /**
     * @return array{issue_count: int, employees: array<int, array<string, mixed>>, checked: int}
     */
    public function handle(PayrollPeriod $period): array
    {
        $employees = $this->population($period);
        $rows = [];
        $issueCount = 0;

        foreach ($employees as $employee) {
            $issues = $this->issuesFor($employee, $period);

            if ($issues === []) {
                continue;
            }

            $issueCount += count($issues);
            $rows[] = [
                'employee_id' => $employee->id,
                'employee' => $employee->fullName(),
                'employee_code' => $employee->employee_code,
                'issues' => $issues,
            ];
        }

        return [
            'issue_count' => $issueCount,
            'checked' => $employees->count(),
            'employees' => $rows,
        ];
    }

    /**
     * @return Collection<int, Employee>
     */
    public function population(PayrollPeriod $period): Collection
    {
        return Employee::whereHas('assignments', fn ($query) => $query
            ->activeOn($period->end_date)
            ->where('is_primary', true))
            ->orderBy('first_name')->orderBy('last_name')->get();
    }

    /**
     * @return array<int, string>
     */
    private function issuesFor(Employee $employee, PayrollPeriod $period): array
    {
        $issues = [];

        if (SalaryStructure::activeFor($employee->id, $period->end_date) === null) {
            $issues[] = 'No salary structure is in force for this period.';
        }

        // SCH-01/06: without a schedule there is no calendar to say which days
        // were working days, so attendance cannot be interpreted.
        if ($this->resolver->resolveFor($employee, $period->end_date) === null) {
            $issues[] = 'No shift or holiday calendar is assigned.';
        }

        $unresolved = DailyAttendance::where('employee_id', $employee->id)
            ->whereDate('attendance_date', '>=', $period->start_date->toDateString())
            ->whereDate('attendance_date', '<=', $period->end_date->toDateString())
            ->where('holds_payroll', true)
            ->count();

        if ($unresolved > 0) {
            // PAY-09 blocks the payslip anyway; surfacing it here means HR fixes
            // it before running rather than discovering it in the results.
            $issues[] = sprintf(
                '%d day%s of attendance is unresolved (missing punch).',
                $unresolved,
                $unresolved === 1 ? '' : 's',
            );
        }

        $provisional = DailyAttendance::where('employee_id', $employee->id)
            ->whereDate('attendance_date', '>=', $period->start_date->toDateString())
            ->whereDate('attendance_date', '<=', $period->end_date->toDateString())
            ->where('is_provisional', true)
            ->count();

        if ($provisional > 0) {
            // POL-06: a provisional status is the system's proposal, not a
            // payroll-eligible fact. Paying on one would skip the human review.
            $issues[] = sprintf(
                '%d day%s of attendance still awaits HR review.',
                $provisional,
                $provisional === 1 ? '' : 's',
            );
        }

        $payment = $this->paymentIssue($employee);

        if ($payment !== null) {
            $issues[] = $payment;
        }

        return $issues;
    }

    /**
     * Whether this employee can actually be paid (SAL-08).
     *
     * Once the employee's own payment method is recorded, this asks the right
     * question rather than a blanket one: a bank transfer needs an account
     * number and an MFS payment needs an MFS number, while cash and cheque are
     * handed over in person and need nothing stored. Demanding an account from
     * someone paid in cash would block a payroll over a rule nobody stated.
     *
     * With no method recorded there is nothing to be specific about, so the
     * original check stands — an employee with neither electronic route on file
     * and no stated method cannot be paid by any means the system can see.
     */
    private function paymentIssue(Employee $employee): ?string
    {
        $method = $employee->salary_payment_method;

        if ($method === null) {
            return blank($employee->bank_account_number) && blank($employee->mfs_account_number)
                ? 'No bank account or mobile financial service details recorded.'
                : null;
        }

        if (! $method->needsStoredAccount()) {
            return null;
        }

        $missing = $method === SalaryPaymentMethod::BankTransfer
            ? blank($employee->bank_account_number)
            : blank($employee->mfs_account_number);

        return $missing
            ? sprintf('Paid by %s, but no account details are recorded for it.', $method->label())
            : null;
    }
}
