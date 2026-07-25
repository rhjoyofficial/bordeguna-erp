<?php

namespace App\Modules\Hrm\Actions;

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

        if ($this->missingPaymentDetails($employee)) {
            $issues[] = 'No bank account or mobile financial service details recorded.';
        }

        return $issues;
    }

    /**
     * SAL-08 allows bank, MFS, cash or cheque, so this only complains when the
     * employee has neither electronic route recorded — cash and cheque need no
     * stored details.
     */
    private function missingPaymentDetails(Employee $employee): bool
    {
        return blank($employee->bank_account_number) && blank($employee->mfs_account_number);
    }
}
