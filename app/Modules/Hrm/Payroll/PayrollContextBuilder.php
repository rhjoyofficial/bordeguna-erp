<?php

namespace App\Modules\Hrm\Payroll;

use App\Modules\Hrm\Enums\AttendanceStatus;
use App\Modules\Hrm\Models\BonusAward;
use App\Modules\Hrm\Models\DailyAttendance;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\LeaveRequest;
use App\Modules\Hrm\Models\OvertimeAssignment;
use App\Modules\Hrm\Models\PayrollAdjustment;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Models\SalaryStructure;
use App\Modules\Hrm\Payroll\Data\AttendanceFacts;
use App\Modules\Hrm\Payroll\Data\EmployeePayrollContext;
use App\Modules\Hrm\Payroll\Data\PayrollInput;
use App\Modules\Hrm\Salary\SalaryBreakdown;
use App\Modules\Hrm\Support\PayrollBalanceLedger;
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
    /**
     * @param  SalaryStructure|null  $structure  overrides the structure in force,
     *                                           so SAL-05's arrear calculation can
     *                                           re-price a closed month against the
     *                                           exact structure it was paid on
     */
    public function build(
        Employee $employee,
        PayrollPeriod $period,
        PayrollConfig $config,
        ?SalaryStructure $structure = null,
    ): EmployeePayrollContext {
        $spec = $period->toSpec();
        $structure ??= SalaryStructure::activeFor($employee->id, $spec->end);

        return new EmployeePayrollContext(
            employeeId: $employee->id,
            period: $spec,
            salary: $structure?->breakdown() ?? SalaryBreakdown::empty(),
            attendance: $this->attendanceFacts($employee, $spec->start, $spec->end),
            // ADJ-01 ticked "only approved overtime assignment", so hours come
            // from an authorisation rather than from punches — which also keeps
            // the attendance engine entirely out of payroll's reach.
            overtimeHours: $this->overtimeHours($employee, $spec->start, $spec->end),
            allowances: $this->bonuses($employee, $period),
            // ADJ-07's loans arrive in Phase 3e; the seam stays open.
            loanInstallments: [],
            adjustments: $this->adjustments($employee, $period),
            config: $config,
            joiningDate: $employee->joining_date?->toDateString(),
            separationDate: $employee->separation_date?->toDateString(),
            overtimeEligible: (bool) ($structure?->overtime_eligible ?? false),
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
     * The approved overtime hours payroll may price (ADJ-01, ADJ-04).
     *
     * Holiday work is excluded at the query, not filtered later, because ADJ-04
     * already compensated it in leave when the assignment was approved. Paying
     * for the hours *and* crediting the day off would compensate the same work
     * twice — the two outcomes are alternatives, never a pair.
     */
    private function overtimeHours(Employee $employee, Carbon $start, Carbon $end): float
    {
        return round((float) OvertimeAssignment::where('employee_id', $employee->id)
            ->payable()
            ->inPeriod($start, $end)
            ->sum('hours'), 2);
    }

    /**
     * Approved bonus awards falling in this payroll month (ADJ-05).
     *
     * The stored amount is used as it stands. It was resolved against the salary
     * in force when the award was made, and a percentage recomputed today would
     * let an increment since then quietly restate what a festival bonus was
     * worth.
     *
     * @return array<int, PayrollInput>
     */
    private function bonuses(Employee $employee, PayrollPeriod $period): array
    {
        return BonusAward::with('event')
            ->where('employee_id', $employee->id)
            ->whereHas('event', fn ($q) => $q->where('payroll_period_id', $period->id))
            ->approved()
            ->get()
            ->map(fn (BonusAward $award) => PayrollInput::earning(
                code: 'bonus',
                label: $award->event?->name ?? 'Bonus',
                amount: (float) $award->amount,
                note: $award->basis_note,
                source: "bonus_award:{$award->id}",
            ))
            ->all();
    }

    /**
     * Everything else moving money this month: the manual adjustments of
     * ADJ-08/09, and the two halves of PAY-11's carry-forward balance.
     *
     * The balance halves are opposites and must not be confused. A **credit**
     * squares a month that could not pay for itself, bringing its net to exactly
     * zero. A **recovery** takes back what an earlier month could not. Netting
     * them against each other inside a single month would hide the debt entirely.
     *
     * @return array<int, PayrollInput>
     */
    private function adjustments(Employee $employee, PayrollPeriod $period): array
    {
        $inputs = PayrollAdjustment::where('employee_id', $employee->id)
            ->where('payroll_period_id', $period->id)
            ->payable()
            ->get()
            ->map(fn (PayrollAdjustment $adjustment) => new PayrollInput(
                code: $adjustment->type->value,
                label: $adjustment->type->label(),
                amount: (float) $adjustment->amount,
                type: $adjustment->type->componentType(),
                note: $adjustment->reason,
                source: "payroll_adjustment:{$adjustment->id}",
            ))
            ->all();

        $ledger = app(PayrollBalanceLedger::class);

        $carried = $ledger->carriedForwardIn($employee->id, $period);

        if ($carried > 0) {
            $inputs[] = PayrollInput::earning(
                code: 'balance_carry_forward',
                label: 'Shortfall carried forward',
                amount: $carried,
                note: 'Net could not be paid in full; the shortfall is carried forward for recovery (PAY-11).',
                source: 'payroll_balance',
            );
        }

        $outstanding = $ledger->outstandingBefore($employee->id, $period);

        if ($outstanding > 0) {
            $inputs[] = PayrollInput::deduction(
                code: 'balance_recovery',
                label: 'Carried-forward recovery',
                amount: $outstanding,
                note: 'Recovery of a shortfall carried forward from an earlier payroll (PAY-11).',
                source: 'payroll_balance',
            );
        }

        return $inputs;
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
