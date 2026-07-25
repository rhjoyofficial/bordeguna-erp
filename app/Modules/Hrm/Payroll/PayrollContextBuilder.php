<?php

namespace App\Modules\Hrm\Payroll;

use App\Modules\Hrm\Enums\AttendanceStatus;
use App\Modules\Hrm\Models\DailyAttendance;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\LeaveRequest;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Models\SalaryStructure;
use App\Modules\Hrm\Payroll\Data\AttendanceFacts;
use App\Modules\Hrm\Payroll\Data\EmployeePayrollContext;
use App\Modules\Hrm\Salary\SalaryBreakdown;
use Illuminate\Support\Carbon;

/**
 * The Gather half of the pipeline — the only part of payroll that touches the
 * database.
 *
 * It reads three independent sources of truth and recomputes none of them:
 * Attendance decided each day's status and consequence, Leave decided what was
 * paid and what became unpaid, and Salary decided the contractual figures. This
 * assembles their answers into one immutable snapshot and hands it to a pure
 * engine — which is what PAY-12 means by a cutoff snapshot, and what keeps the
 * "Attendance provides facts, Payroll calculates money" boundary real rather
 * than aspirational.
 */
class PayrollContextBuilder
{
    public function build(Employee $employee, PayrollPeriod $period, PayrollConfig $config): EmployeePayrollContext
    {
        $spec = $period->toSpec();
        $structure = SalaryStructure::activeFor($employee->id, $spec->end);

        return new EmployeePayrollContext(
            employeeId: $employee->id,
            period: $spec,
            salary: $structure?->breakdown() ?? SalaryBreakdown::empty(),
            attendance: $this->attendanceFacts($employee, $spec->start, $spec->end),
            // ADJ-01 ticked "only approved overtime assignment", so hours come
            // from an authorisation rather than from punches. Those records are
            // Phase 3d's; until then nobody has approved any.
            overtimeHours: 0.0,
            // Phase 3d (bonuses, adjustments) and 3e (loans) fill these. The
            // seams exist now so filling them needs no engine change.
            allowances: [],
            loanInstallments: [],
            adjustments: [],
            config: $config,
            joiningDate: $employee->joining_date?->toDateString(),
            separationDate: $employee->separation_date?->toDateString(),
        );
    }

    public function structureFor(Employee $employee, PayrollPeriod $period): ?SalaryStructure
    {
        return SalaryStructure::activeFor($employee->id, $period->toSpec()->end);
    }

    /**
     * Everything Attendance and Leave already decided about this period.
     *
     * Note what is *not* here: no punch is read, no status is re-derived, no
     * lateness threshold is re-applied. Each figure is a conclusion those
     * modules reached and stored.
     */
    private function attendanceFacts(Employee $employee, Carbon $start, Carbon $end): AttendanceFacts
    {
        $days = DailyAttendance::where('employee_id', $employee->id)
            ->whereDate('attendance_date', '>=', $start->toDateString())
            ->whereDate('attendance_date', '<=', $end->toDateString())
            ->get();

        if ($days->isEmpty()) {
            return AttendanceFacts::none();
        }

        $present = $days->filter(fn (DailyAttendance $d) => in_array($d->status, [
            AttendanceStatus::Present, AttendanceStatus::Late, AttendanceStatus::FieldDuty,
        ], true))->count();

        // A half day counts as half a day worked — the status itself is the
        // attendance engine's conclusion, not something re-derived here.
        $halfDays = $days->where('status', AttendanceStatus::HalfDay)->count();

        $onLeaveDays = $days->where('status', AttendanceStatus::OnLeave)->count();
        $unpaidLeaveDays = $this->unpaidLeaveDays($employee, $start, $end);

        return new AttendanceFacts(
            presentDays: $present + ($halfDays * 0.5),
            absentDays: (float) $days->where('status', AttendanceStatus::Absent)->count(),
            // LEV-07 splits a request into paid and unpaid portions, so the
            // paid part is whatever the leave days were not.
            paidLeaveDays: max(0.0, $onLeaveDays - $unpaidLeaveDays),
            unpaidLeaveDays: $unpaidLeaveDays,
            lateOccurrences: $days->where('late_minutes', '>', 0)->count(),
            lateMinutes: (int) $days->sum('late_minutes'),
            // POL-03's ladder already decided this. A day carrying a deducting
            // consequence forfeits one day's pay ("pro-rata one-day deduction").
            deductibleDays: (float) $days->filter(fn (DailyAttendance $d) => $d->consequence->deducts())->count(),
            // PAY-09.
            hasUnresolvedMissingPunch: $days->where('holds_payroll', true)->isNotEmpty(),
        );
    }

    /**
     * The unpaid portion of leave taken in this period (LEV-07). Counted from
     * the covered dates the leave request stored, so a calendar revision since
     * then cannot change what was charged.
     */
    private function unpaidLeaveDays(Employee $employee, Carbon $start, Carbon $end): float
    {
        return (float) LeaveRequest::where('employee_id', $employee->id)
            ->applied()
            ->get()
            ->sum(function (LeaveRequest $request) use ($start, $end) {
                $covered = collect($request->covered_dates ?? [])
                    ->filter(fn (string $date) => $date >= $start->toDateString() && $date <= $end->toDateString())
                    ->count();

                $total = count($request->covered_dates ?? []);

                if ($total === 0 || $covered === 0) {
                    return 0.0;
                }

                // Apportion the unpaid part across the days that fall inside
                // this period, since a request can straddle two payroll months.
                return round((float) $request->unpaid_days * ($covered / $total), 2);
            });
    }
}
