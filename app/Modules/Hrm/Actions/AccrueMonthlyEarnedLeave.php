<?php

namespace App\Modules\Hrm\Actions;

use App\Modules\Hrm\Attendance\Policy\ElAccrualConfig;
use App\Modules\Hrm\Enums\EmploymentType;
use App\Modules\Hrm\Enums\LeaveLedgerEntryType;
use App\Modules\Hrm\Models\AttendancePolicy;
use App\Modules\Hrm\Models\DailyAttendance;
use App\Modules\Hrm\Models\EarnedLeaveAccrual;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\LeaveLedgerEntry;
use App\Modules\Hrm\Models\LeaveType;
use App\Modules\Hrm\Support\PayrollCycle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The monthly earned-leave accrual (Joy, 24 Aug 2026): "Per month 1 day will
 * per add to each employee quota unless employee do ordinary late more than 3
 * and serious late more than 1."
 *
 * ⚠⚠ **PERMANENT EMPLOYEES ONLY** (Joy, asked and answered the same day). A
 * probationer's whole entitlement is the flat sick grant `GrantAnnualLeaveQuota`
 * makes; earned leave is one of the things confirmation brings, so a
 * probationer with a spotless month accrues nothing. "Permanent" is read as at
 * the LAST DAY of the accrued month: `employment_type` is a current fact rather
 * than an effective-dated one, so a confirmation date, when the record carries
 * one, is what says whether they were permanent back then.
 *
 * ⭐ 27 Oct 2026 (Joy, answering the question this docblock used to ask):
 * "earned leave month must follows 24-23 salary cycle. for employees
 * everything not 1-31 always 24-23". The month accrued is now the PAYROLL
 * CYCLE carrying that label, read from `PayrollCycle::forMonth` (the one place
 * that knows the cycle): month 10 is 24 Sep - 23 Oct. Lates are counted over
 * the cycle's dates and "permanent" is judged on the cycle's last day (the
 * 23rd). A business configured with start day 1 gets the calendar month back.
 *
 * ⚠⚠ History before the switch stays as it was. Live rows for 2026 month 8
 * and month 9 were decided on the CALENDAR month (granted on 1 Sep and 1 Oct,
 * counting 1-31 Aug and 1-30 Sep). Month 10 is the first cycle month, so
 * 24-30 Sep was counted for lates in both month 9 and month 10. Nothing is
 * granted twice: the decision row is unique per (employee, year, month) and
 * the labels do not overlap.
 *
 * ⚠ A cycle that has not ended yet is refused. Its lates are not all in, and
 * the unique row would freeze an early decision for good.
 *
 * ⚠ The thresholds and the rate are policy data on the company-default
 * attendance policy, so HR revises them on the same screen as the ladders and
 * the change is versioned the same way. The WINDOWS are read per employee
 * though: office and retail name their bands differently, and "ordinary" means
 * whichever band each employee's own policy lists first.
 *
 * Idempotent twice over: the decision row is unique per employee and month, and
 * the ledger entry carries a once-key. A rerun decides nothing twice.
 */
class AccrueMonthlyEarnedLeave
{
    /**
     * @return array{granted: int, withheld: int, skipped: int, days: float, reason: ?string, rows: array<int, array<string, mixed>>}
     */
    public function handle(int $year, int $month, bool $dryRun = false): array
    {
        // ⭐ 27 Oct 2026: the payroll cycle with this label, not the calendar month.
        [$monthStart, $monthEnd] = PayrollCycle::forMonth($year, $month);

        if ($monthEnd->gte(Carbon::today())) {
            return $this->empty(sprintf(
                'The cycle %s to %s has not ended yet, so it cannot be accrued.',
                $monthStart->format('j M Y'), $monthEnd->format('j M Y'),
            ));
        }

        $accrual = $this->configFor($monthEnd);

        if (! $accrual->enabled) {
            return $this->empty('Earned leave accrual is switched off in the attendance policy.');
        }

        $earned = LeaveType::where('code', 'earned')->first();

        if ($earned === null || ! $earned->is_active) {
            // Not an error: the type is deliberately inactive until the company
            // decides. The count of who WOULD have accrued travels back with
            // the refusal, so a silent month is never mistaken for a month in
            // which nobody qualified.
            return $this->empty(sprintf(
                'The earned leave type is inactive, so nothing was credited - %d employee(s) would otherwise have been considered.',
                $this->population($monthEnd)->count(),
            ));
        }

        $employees = $this->population($monthEnd);

        $granted = 0;
        $withheld = 0;
        $skipped = 0;
        $rows = [];

        foreach ($employees as $employee) {
            if (EarnedLeaveAccrual::where('employee_id', $employee->id)->forMonth($year, $month)->exists()) {
                $skipped++;

                continue;
            }

            [$ordinary, $serious] = $this->lateCounts($employee, $monthStart, $monthEnd);

            $reason = match (true) {
                $ordinary > $accrual->maxOrdinaryLates => sprintf(
                    '%d ordinary lates in the cycle, more than the %d allowed.',
                    $ordinary, $accrual->maxOrdinaryLates,
                ),
                $serious > $accrual->maxSeriousLates => sprintf(
                    '%d serious lates in the cycle, more than the %d allowed.',
                    $serious, $accrual->maxSeriousLates,
                ),
                default => null,
            };

            $rows[] = [
                'employee_code' => $employee->employee_code,
                'employee' => $employee->fullName(),
                'ordinary_lates' => $ordinary,
                'serious_lates' => $serious,
                'days' => $reason === null ? $accrual->daysPerMonth : 0.0,
                'withheld_reason' => $reason,
            ];

            $reason === null ? $granted++ : $withheld++;

            if ($dryRun) {
                continue;
            }

            $this->record($employee, $earned, $year, $month, $monthStart, $monthEnd, $accrual, $ordinary, $serious, $reason);
        }

        return [
            'granted' => $granted,
            'withheld' => $withheld,
            'skipped' => $skipped,
            'days' => round($granted * $accrual->daysPerMonth, 2),
            'reason' => null,
            'rows' => $rows,
        ];
    }

    /**
     * ⚠ The accrual rule is read from the COMPANY-DEFAULT policy only. A
     * department policy may carry the keys and they are ignored: one accrual
     * rule for the business, or nobody can say what an employee who changed
     * department mid-month earned.
     */
    private function configFor(Carbon $monthEnd): ElAccrualConfig
    {
        $policy = AttendancePolicy::inForceOn($monthEnd);

        return $policy === null
            ? new ElAccrualConfig
            : $policy->toConfig()->elAccrual;
    }

    /**
     * Everyone with a primary assignment in force at the end of the month who
     * was PERMANENT by then. A confirmation date after the month's end means
     * they were still on probation while it was being earned.
     *
     * @return Collection<int, Employee>
     */
    private function population(Carbon $monthEnd): Collection
    {
        return Employee::query()
            ->whereHas('assignments', fn ($query) => $query
                ->where('is_primary', true)
                ->activeOn($monthEnd))
            ->where('employment_type', EmploymentType::Permanent)
            ->where(fn ($query) => $query
                ->whereNull('confirmation_date')
                ->orWhereDate('confirmation_date', '<=', $monthEnd->toDateString()))
            ->orderBy('employee_code')
            ->get();
    }

    /**
     * This employee's occurrence counts in the month's first two lateness
     * bands, read from the policy THEY were judged by.
     *
     * @return array{0: int, 1: int}
     */
    private function lateCounts(Employee $employee, Carbon $monthStart, Carbon $monthEnd): array
    {
        $assignment = $employee->assignments()
            ->where('is_primary', true)
            ->activeOn($monthEnd)
            ->first();

        $policy = AttendancePolicy::inForceOn($monthEnd, $assignment?->department_id);
        $windows = $policy === null ? [] : $policy->toConfig()->graceWindows;

        // Ordered by lateness, so the first band is the ordinary one and the
        // second the serious one, whatever each policy calls them. A policy
        // with only one band simply has no serious count.
        $ordinaryKey = $windows[0]->key ?? null;
        $seriousKey = $windows[1]->key ?? null;

        $counts = DailyAttendance::query()
            ->where('employee_id', $employee->id)
            ->whereNotNull('grace_window_key')
            ->whereDate('attendance_date', '>=', $monthStart->toDateString())
            ->whereDate('attendance_date', '<=', $monthEnd->toDateString())
            ->selectRaw('grace_window_key, count(*) as total')
            ->groupBy('grace_window_key')
            ->pluck('total', 'grace_window_key');

        return [
            $ordinaryKey === null ? 0 : (int) $counts->get($ordinaryKey, 0),
            $seriousKey === null ? 0 : (int) $counts->get($seriousKey, 0),
        ];
    }

    private function record(
        Employee $employee,
        LeaveType $earned,
        int $year,
        int $month,
        Carbon $monthStart,
        Carbon $monthEnd,
        ElAccrualConfig $accrual,
        int $ordinary,
        int $serious,
        ?string $reason,
    ): void {
        DB::transaction(function () use ($employee, $earned, $year, $month, $monthStart, $monthEnd, $accrual, $ordinary, $serious, $reason) {
            EarnedLeaveAccrual::create([
                'employee_id' => $employee->id,
                'year' => $year,
                'month' => $month,
                'days_granted' => $reason === null ? $accrual->daysPerMonth : 0,
                'withheld_reason' => $reason,
                'ordinary_lates' => $ordinary,
                'serious_lates' => $serious,
            ]);

            if ($reason !== null) {
                // Withheld months write no ledger entry - a balance may only
                // move for days that were actually earned. The decision row
                // above is where the reason lives.
                return;
            }

            LeaveLedgerEntry::once(
                sprintf('el_accrual:%d:%d:%d', $year, $month, $employee->id),
                [
                    'employee_id' => $employee->id,
                    'leave_type_id' => $earned->id,
                    'year' => $year,
                    'entry_type' => LeaveLedgerEntryType::Grant,
                    'days' => $accrual->daysPerMonth,
                    'occurred_on' => $monthEnd->toDateString(),
                    'note' => sprintf(
                        'Earned leave for %s (%s to %s): %s day(s). %d ordinary and %d serious lates, within the allowance.',
                        $monthEnd->format('F Y'),
                        $monthStart->format('j M'),
                        $monthEnd->format('j M Y'),
                        rtrim(rtrim(number_format($accrual->daysPerMonth, 2), '0'), '.'),
                        $ordinary,
                        $serious,
                    ),
                    'created_by' => Auth::id(),
                ],
            );
        });
    }

    /**
     * @return array{granted: int, withheld: int, skipped: int, days: float, reason: ?string, rows: array<int, array<string, mixed>>}
     */
    private function empty(string $reason): array
    {
        return ['granted' => 0, 'withheld' => 0, 'skipped' => 0, 'days' => 0.0, 'reason' => $reason, 'rows' => []];
    }
}
