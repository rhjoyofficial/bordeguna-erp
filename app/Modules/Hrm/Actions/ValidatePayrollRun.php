<?php

namespace App\Modules\Hrm\Actions;

use App\Modules\Hrm\Enums\LoanInstallmentStatus;
use App\Modules\Hrm\Enums\SalaryPaymentMethod;
use App\Modules\Hrm\Models\DailyAttendance;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\Loan;
use App\Modules\Hrm\Models\PayrollPeriod;
use App\Modules\Hrm\Models\SalaryStructure;
use App\Modules\Hrm\Support\ScheduleResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
 *
 * ---
 *
 * **TWO CHANNELS, AND THE DIFFERENCE IS THE WHOLE POINT (Joy, 2026-08-01).**
 *
 * `issues` BLOCK. They are reserved for conditions under which payroll must
 * never proceed — no salary structure, no payment details, an unresolved punch.
 *
 * `warnings` NEVER block. They exist for facts that are usually fine and
 * occasionally the first sign of something wrong, where refusing to pay anybody
 * would be the wrong response. A missing attendance record is the founding
 * example: expected during go-live, partial periods, mid-month joining,
 * historical imports and salary-only payroll — and also exactly what a stalled
 * scheduler looks like.
 *
 * ⚠ Warnings are a SEPARATE top-level key rather than extra entries in
 * `employees`. Anything that lands in `employees` is understood by its readers
 * — and by `CalculatePayroll` — as a reason not to pay, and quietly widening
 * that list is how a warning turns into a blocker nobody intended.
 */
class ValidatePayrollRun
{
    public function __construct(private readonly ScheduleResolver $resolver) {}

    /**
     * @return array{issue_count: int, warning_count: int, employees: array<int, array<string, mixed>>, warnings: array<int, array<string, mixed>>, checked: int}
     */
    public function handle(PayrollPeriod $period): array
    {
        $employees = $this->population($period);
        $rows = [];
        $warningRows = [];
        $issueCount = 0;
        $warningCount = 0;

        foreach ($employees as $employee) {
            $issues = $this->issuesFor($employee, $period);

            if ($issues !== []) {
                $issueCount += count($issues);
                $rows[] = [
                    'employee_id' => $employee->id,
                    'employee' => $employee->fullName(),
                    'employee_code' => $employee->employee_code,
                    'issues' => $issues,
                ];
            }

            $warnings = $this->warningsFor($employee, $period);

            if ($warnings !== []) {
                $warningCount += count($warnings);
                $warningRows[] = [
                    'employee_id' => $employee->id,
                    'employee' => $employee->fullName(),
                    'employee_code' => $employee->employee_code,
                    'warnings' => $warnings,
                ];
            }
        }

        return [
            'issue_count' => $issueCount,
            'warning_count' => $warningCount,
            'checked' => $employees->count(),
            'employees' => $rows,
            'warnings' => $warningRows,
        ];
    }

    /**
     * Things worth seeing before approving, none of which stop the calculation.
     *
     * @return array<int, string>
     */
    private function warningsFor(Employee $employee, PayrollPeriod $period): array
    {
        $warnings = [];

        $unrecorded = $this->unrecordedDays($employee, $period);

        if ($unrecorded > 0) {
            $warnings[] = sprintf(
                '%d elapsed day%s of this period %s no attendance record. '
                .'Attendance appears incomplete — please verify before approval.',
                $unrecorded,
                $unrecorded === 1 ? '' : 's',
                $unrecorded === 1 ? 'has' : 'have',
            );
        }

        foreach ($this->loanWarnings($employee, $period) as $warning) {
            $warnings[] = $warning;
        }

        return $warnings;
    }

    /**
     * ⚠⚠ The August 2026 wipe bug, remembered as a check. An active loan whose
     * month has NO scheduled installment produces no deduction line and no
     * complaint - the run foots perfectly and the money is simply not
     * recovered. That once happened because a script set installments to a
     * status outside the enum; it can equally happen from an exhausted or
     * hand-edited schedule. Whatever the cause, the validator now says so.
     *
     * The corrupt-status check reads the table RAW, because hydrating a row
     * whose status is not in the enum would throw before it could warn.
     *
     * @return list<string>
     */
    private function loanWarnings(Employee $employee, PayrollPeriod $period): array
    {
        $warnings = [];

        $loans = Loan::with('installments')
            ->forEmployee($employee->id)
            ->recoverable()
            ->applied()
            ->get();

        foreach ($loans as $loan) {
            if ($loan->outstanding() <= 0.005) {
                continue;
            }

            $corrupt = DB::table('loan_installments')
                ->where('loan_id', $loan->id)
                ->whereNotIn('status', array_column(LoanInstallmentStatus::cases(), 'value'))
                ->pluck('status')
                ->unique();

            if ($corrupt->isNotEmpty()) {
                $warnings[] = sprintf(
                    'Loan %s has installment rows with unknown status "%s" - payroll cannot see them. '
                    .'Fix the installment status before calculating, or nothing will be recovered.',
                    $loan->reference,
                    $corrupt->join('", "'),
                );

                continue;
            }

            // HRM-002 (14 Oct 2026): an installment an earlier version of this
            // month already recovered is still deducted, so it is no gap.
            // Rule #21 (14 Oct 2026): nor is an installment carried in from an
            // earlier month whose payroll was approved without it.
            if ($loan->installmentDueIn((int) $period->year, (int) $period->month) === null
                && $loan->installmentRecoveredIn($period) === null
                && $loan->installmentsCarriedInto($period)->isEmpty()) {
                $warnings[] = sprintf(
                    'Loan %s still has %s outstanding but no scheduled installment for this month - '
                    .'payroll will deduct nothing. Check the installment schedule, or settle the loan.',
                    $loan->reference,
                    number_format($loan->outstanding(), 2),
                );
            }
        }

        return $warnings;
    }

    /**
     * Elapsed days in the period for which no `daily_attendances` row exists.
     *
     * ⚠⚠ **A DAY WITH NO ROW IS NOT ABSENT — IT IS INVISIBLE.** `AttendanceFacts`
     * builds `absentDays` by counting rows whose status is Absent, so a day that
     * was never processed produces no deduction, no allowance and no warning of
     * its own. It is silently paid in full and the payslip foots perfectly.
     *
     * Normally that cannot happen: `ProcessAttendanceDaily` writes a row per
     * employee per day, weekly offs and holidays included. But it reprocesses
     * only **today plus seven days**, so if the scheduler stops for more than
     * eight days those dates fall out of the window and no row is ever created.
     *
     * Found on 2026-08-01 by running the engine on real data for the first time:
     * one employee had 9 attendance rows across a 31-day period and was paid his
     * full gross with zero deductions. Correct in that instance — the other 22
     * days had not happened yet — which is why this counts only days that HAVE
     * elapsed, and only while the employee was actually on the books.
     */
    private function unrecordedDays(Employee $employee, PayrollPeriod $period): int
    {
        $start = $period->start_date->copy()->startOfDay();
        $end = $period->end_date->copy()->startOfDay();

        // Days still in the future are not missing, they are not yet due.
        // Without this every mid-period run warns about its own remainder,
        // which would train people to ignore the warning — the worst outcome.
        $today = now()->startOfDay();

        if ($today->lt($end)) {
            $end = $today;
        }

        // A mid-period joiner owes nothing before they started, and a leaver
        // owes nothing after their last day.
        if ($employee->joining_date !== null && $employee->joining_date->gt($start)) {
            $start = $employee->joining_date->copy()->startOfDay();
        }

        if ($employee->separation_date !== null && $employee->separation_date->lt($end)) {
            $end = $employee->separation_date->copy()->startOfDay();
        }

        if ($end->lt($start)) {
            return 0;
        }

        $expected = (int) $start->diffInDays($end) + 1;

        $recorded = DailyAttendance::where('employee_id', $employee->id)
            ->whereDate('attendance_date', '>=', $start->toDateString())
            ->whereDate('attendance_date', '<=', $end->toDateString())
            ->distinct()
            ->count('attendance_date');

        return max(0, $expected - $recorded);
    }

    /**
     * @return Collection<int, Employee>
     */
    public function population(PayrollPeriod $period): Collection
    {
        // ⭐ 27 Sep 2026 (Joy): everyone who worked ANY part of the cycle is on the
        // run, including a mid-cycle joiner or leaver. Proration then pays them
        // for the days they were employed. `activeOn(end)` alone dropped anyone
        // who left before the 23rd, so a leaver's final month was never paid.
        return Employee::whereHas('assignments', fn ($query) => $query
            ->activeDuring($period->start_date, $period->end_date)
            ->where('is_primary', true))
            ->orderBy('employee_code')->get();
    }

    /**
     * @return array<int, string>
     */
    /** Public accessor: the blocking issues for one employee's payslip. */
    public function issuesForEmployee(Employee $employee, PayrollPeriod $period): array
    {
        return $this->issuesFor($employee, $period);
    }

    private function issuesFor(Employee $employee, PayrollPeriod $period): array
    {
        $issues = [];

        // ⭐ 27 Sep 2026 (Joy): a payslip is judged over the days the person was
        // actually employed in the period. `$to` is their last employed day (or
        // the period end). A leaver's assignment and schedule end that day, so
        // checking "as of the period end" wrongly reports "no schedule assigned"
        // for someone who left mid-cycle; and days after it neither hold payroll
        // nor block the run.
        $from = ($employee->joining_date !== null && $employee->joining_date->gt($period->start_date))
            ? $employee->joining_date->toDateString()
            : $period->start_date->toDateString();
        $to = ($employee->separation_date !== null && $employee->separation_date->lt($period->end_date))
            ? $employee->separation_date->toDateString()
            : $period->end_date->toDateString();

        if (SalaryStructure::activeFor($employee->id, $to) === null) {
            $issues[] = 'No salary structure is in force for this period.';
        }

        // SCH-01/06: without a schedule there is no calendar to say which days
        // were working days, so attendance cannot be interpreted. Read as of the
        // last employed day so a leaver's closed assignment is not mistaken for
        // "never had one".
        if ($this->resolver->resolveFor($employee, $to) === null) {
            $issues[] = 'No shift or holiday calendar is assigned.';
        }

        $unresolved = DailyAttendance::where('employee_id', $employee->id)
            ->whereDate('attendance_date', '>=', $from)
            ->whereDate('attendance_date', '<=', $to)
            ->where('holds_payroll', true)
            ->count();

        if ($unresolved > 0) {
            // PAY-09 blocks the payslip anyway; surfacing it here means HR fixes
            // it before running rather than discovering it in the results.
            // ⚠ The VERB agrees, not only the noun. "2 days of attendance is
            // unresolved" shipped for months and read as broken English on the
            // one screen somebody stares at before releasing a month of salary.
            // Second occurrence in this project — "1 employee have punched
            // today" on the dashboard was the first — and both survived because
            // `toContain('2 days')` passes either way.
            $issues[] = sprintf(
                '%d day%s of attendance %s unresolved (missing punch).',
                $unresolved,
                $unresolved === 1 ? '' : 's',
                $unresolved === 1 ? 'is' : 'are',
            );
        }

        $provisional = DailyAttendance::where('employee_id', $employee->id)
            ->whereDate('attendance_date', '>=', $from)
            ->whereDate('attendance_date', '<=', $to)
            ->where('is_provisional', true)
            ->count();

        if ($provisional > 0) {
            // POL-06: a provisional status is the system's proposal, not a
            // payroll-eligible fact. Paying on one would skip the human review.
            $issues[] = sprintf(
                '%d day%s of attendance still %s HR review.',
                $provisional,
                $provisional === 1 ? '' : 's',
                $provisional === 1 ? 'awaits' : 'await',
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
