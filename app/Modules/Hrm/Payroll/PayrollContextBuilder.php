<?php

namespace App\Modules\Hrm\Payroll;

use App\Modules\Accounting\Models\FinancePolicy;
use App\Modules\Hrm\Enums\AttendanceStatus;
use App\Modules\Hrm\Enums\LoanStatus;
use App\Modules\Hrm\Models\AttendanceWaiver;
use App\Modules\Hrm\Models\BonusAward;
use App\Modules\Hrm\Models\DailyAttendance;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\LeaveRequest;
use App\Modules\Hrm\Models\Loan;
use App\Modules\Hrm\Models\OvertimeAssignment;
use App\Modules\Hrm\Models\PayrollAdjustment;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Models\PayrollRun;
use App\Modules\Hrm\Models\Payslip;
use App\Modules\Hrm\Models\PayslipLine;
use App\Modules\Hrm\Models\SalaryStructure;
use App\Modules\Hrm\Payroll\Data\AttendanceFacts;
use App\Modules\Hrm\Payroll\Data\EmployeePayrollContext;
use App\Modules\Hrm\Payroll\Data\PayrollInput;
use App\Modules\Hrm\Payroll\Stages\ApplyStaffPurchaseRecovery;
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
     * @param  bool  $withStaffPurchase  false for SAL-05's before/after
     *                                   comparison: a capped recovery is a share
     *                                   of the net, so it would shrink the
     *                                   arrear it is compared against
     */
    public function build(
        Employee $employee,
        PayrollPeriod $period,
        PayrollConfig $config,
        ?SalaryStructure $structure = null,
        bool $withStaffPurchase = true,
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
            loanInstallments: $this->loanInstallments($employee, $period),
            adjustments: $this->adjustments($employee, $period),
            config: $config,
            joiningDate: $employee->joining_date?->toDateString(),
            separationDate: $employee->separation_date?->toDateString(),
            overtimeEligible: (bool) ($structure?->overtime_eligible ?? false),
            staffPurchaseOwed: $withStaffPurchase ? $this->staffPurchaseOwed($employee, $period) : 0.0,
            staffPurchaseCapPercent: FinancePolicy::for((int) $period->business_id)->staff_purchase_cap_percent,
        );
    }

    /** The source_key prefix StaffPurchaseController writes on a salary-deduction hand-over. */
    public const STAFF_PURCHASE_KEY = 'staff_purchase:';

    /**
     * B8: what the employee still owes for staff purchases, as at this period.
     *
     * Derived, never stored: every staff purchase charged to this period or an
     * earlier one, less every staff purchase recovery on the payslips of the
     * earlier periods (the latest version of each that was not cancelled or
     * rejected). Whatever a capped month did not take is therefore still owed
     * the next month without anything being written to carry it, and re-running
     * an open payroll can never recover the same purchase twice.
     */
    private function staffPurchaseOwed(Employee $employee, PayrollPeriod $period): float
    {
        $periodStart = $period->start_date->toDateString();

        $charged = (float) PayrollAdjustment::where('employee_id', $employee->id)
            ->where('source_key', 'like', self::STAFF_PURCHASE_KEY.'%')
            ->payable()
            ->whereHas('period', fn ($p) => $p->whereDate('start_date', '<=', $periodStart))
            ->sum('amount');

        if ($charged <= 0) {
            return 0.0;
        }

        $runIds = PayrollPeriod::query()
            ->where('business_id', $period->business_id)
            ->whereDate('start_date', '<', $periodStart)
            ->with('runs')
            ->get()
            ->map(fn (PayrollPeriod $earlier) => $earlier->runs
                ->first(fn (PayrollRun $run) => ! $run->status->isVoided())?->id)
            ->filter()
            ->values();

        $recovered = $runIds->isEmpty() ? 0.0 : (float) PayslipLine::query()
            ->where('code', ApplyStaffPurchaseRecovery::CODE)
            ->whereIn('payslip_id', Payslip::query()
                ->where('employee_id', $employee->id)
                ->whereIn('payroll_run_id', $runIds)
                ->select('id'))
            ->sum('amount');

        return max(0.0, round($charged - $recovered, 2));
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
        // ⭐ 27 Sep 2026 (Joy): count only days the person was actually employed.
        // A leaver's days after their last working day (and a joiner's before
        // their first) must not be counted - proration already limits their pay,
        // and counting a leaver's later "Absent" days would dock someone who had
        // already been paid off. The nightly job may have written those days
        // before the separation was recorded.
        if ($employee->separation_date !== null && $employee->separation_date->lt($end)) {
            $end = $employee->separation_date->copy()->startOfDay();
        }

        if ($employee->joining_date !== null && $employee->joining_date->gt($start)) {
            $start = $employee->joining_date->copy()->startOfDay();
        }

        if ($end->lt($start)) {
            return AttendanceFacts::none();
        }

        $days = DailyAttendance::where('employee_id', $employee->id)
            ->whereDate('attendance_date', '>=', $start->toDateString())
            ->whereDate('attendance_date', '<=', $end->toDateString())
            ->get();

        if ($days->isEmpty()) {
            return AttendanceFacts::none();
        }

        // Days a permitted person has forgiven (24 Aug 2026). The waiver
        // changes PRICING only: a waived absent day leaves absentDays and a
        // waived late-deduction day leaves deductibleDays, but neither joins
        // presentDays or daysReportedForWork - forgiving the deduction does
        // not invent attendance, so the per-present-day allowance and
        // proration stay exactly as the engine found them.
        $waivedDates = AttendanceWaiver::query()->active()
            ->where('employee_id', $employee->id)
            ->whereDate('attendance_date', '>=', $start->toDateString())
            ->whereDate('attendance_date', '<=', $end->toDateString())
            ->get()
            ->map(fn (AttendanceWaiver $w) => $w->attendance_date->toDateString())
            ->all();

        $isWaived = fn (DailyAttendance $d) => in_array($d->attendance_date->toDateString(), $waivedDates, true);

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
            absentDays: (float) $days->filter(fn (DailyAttendance $d) => $d->status === AttendanceStatus::Absent && ! $isWaived($d))->count(),
            // LEV-07 splits a request into paid and unpaid portions, so the
            // paid part is whatever the leave days were not.
            paidLeaveDays: max(0.0, $onLeaveDays - $unpaidLeaveDays),
            unpaidLeaveDays: $unpaidLeaveDays,
            lateOccurrences: $days->where('late_minutes', '>', 0)->count(),
            lateMinutes: (int) $days->sum('late_minutes'),
            // POL-03's ladder already decided this. A day carrying a deducting
            // consequence forfeits one day's pay ("pro-rata one-day deduction")
            // - unless a permitted person forgave that day.
            deductibleDays: (float) $days->filter(fn (DailyAttendance $d) => $d->consequence->deducts() && ! $isWaived($d))->count(),
            // PAY-09.
            hasUnresolvedMissingPunch: $days->where('holds_payroll', true)->isNotEmpty(),
            // C14. Counted from the day itself rather than derived from
            // `presentDays`, because the two rules genuinely differ: a half day
            // is a whole day of reporting for work, and an approved holiday
            // worked counts here while carrying a `Holiday` status that keeps
            // it out of `presentDays` altogether.
            daysReportedForWork: $days->filter(fn (DailyAttendance $d) => $d->reportedForWork())->count(),
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
     * The loan installments due this payroll month (ADJ-07).
     *
     * **This method must never read a balance the current run has already
     * changed, and it does not.** A repayment is posted to the loan ledger only
     * when a run is *disbursed*, so every recalculation of an open run sees the
     * same outstanding figure and produces the same deduction. That is what
     * keeps loan recovery non-circular: the amount deducted is the scheduled
     * installment, a fixed number decided when the loan was disbursed — never
     * something derived from the net it is about to reduce.
     *
     * It is also why nothing here tries to be affordable. Capping an installment
     * at what the payslip can bear would make the deduction depend on the net
     * and the net depend on the deduction. Instead the installment stands, and
     * if it takes the net below zero PAY-11 blocks the payslip for a human —
     * which is a decision, not a silent adjustment.
     *
     * @return array<int, PayrollInput>
     */
    private function loanInstallments(Employee $employee, PayrollPeriod $period): array
    {
        // HRM-002 (audit 14 Oct 2026). Not only recoverable loans: a loan the
        // locked version's installment closed is Settled now, yet that
        // installment must stay on the unlocked version of the same month.
        $loans = Loan::with('installments')
            ->forEmployee($employee->id)
            ->applied()
            ->whereIn('status', [LoanStatus::Active, LoanStatus::Settled])
            ->get();

        $inputs = [];

        foreach ($loans as $loan) {
            $installment = $loan->status === LoanStatus::Active
                ? $loan->installmentDueIn((int) $period->year, (int) $period->month)
                : null;

            // Already recovered by another version of this month (an unlock):
            // still deducted, so the new version's net is not inflated by it,
            // and the balance after it is today's balance, which already
            // reflects that recovery.
            $alreadyRecovered = false;

            if ($installment === null) {
                $installment = $loan->installmentRecoveredIn($period);
                $alreadyRecovered = $installment !== null;
            }

            if ($installment === null) {
                continue;
            }

            $outstanding = $loan->outstanding();
            $remainingAfter = $alreadyRecovered
                ? round(max(0, $outstanding), 2)
                : round(max(0, $outstanding - (float) $installment->amount), 2);

            $inputs[] = PayrollInput::deduction(
                code: 'loan_repayment',
                label: $loan->type->deductionLabel(),
                amount: (float) $installment->amount,
                // PAY-13's explainability requirement, applied to the question a
                // borrower actually asks: which loan, how far through, and how
                // much is left after this.
                note: sprintf(
                    '%s %s — installment %d of %d; %s outstanding after this.',
                    $loan->type->label(),
                    $loan->reference,
                    $installment->sequence,
                    $loan->installment_count,
                    number_format($remainingAfter, 2),
                ),
                source: "loan_installment:{$installment->id}",
                quantity: (float) $installment->sequence,
                rate: (float) $installment->amount,
            );
        }

        return $inputs;
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
            // B8: a staff purchase is recovered by its own capped stage, from
            // the running balance, never in full as a plain deduction.
            ->where(fn ($q) => $q->whereNull('source_key')
                ->orWhere('source_key', 'not like', self::STAFF_PURCHASE_KEY.'%'))
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
