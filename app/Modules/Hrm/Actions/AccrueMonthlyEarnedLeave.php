<?php

namespace App\Modules\Hrm\Actions;

use App\Modules\Hrm\Attendance\Policy\ElAccrualConfig;
use App\Modules\Hrm\Enums\EmploymentType;
use App\Modules\Hrm\Enums\LeaveLedgerEntryType;
use App\Modules\Hrm\Enums\PayrollStatus;
use App\Modules\Hrm\Models\AttendancePolicy;
use App\Modules\Hrm\Models\DailyAttendance;
use App\Modules\Hrm\Models\EarnedLeaveAccrual;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\LeaveLedgerEntry;
use App\Modules\Hrm\Models\LeaveType;
use App\Modules\Hrm\Models\PayrollRun;
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
 * ⭐ 27 Oct 2026, later the same day (Joy): "reset full recount from aug 24 to
 * sep 23, sep 24 to oct 23 based on that add the amount, from the 24 oct to
 * nov 23 will calculate, main part is max time i will import the attendence
 * end of the month so no after i data update will be caculated when payroll
 * runs." Two consequences:
 *
 *  - The calendar decisions live made for 2026 months 8 and 9 are superseded
 *    and their grants reversed (`supersede`), and month 9 is decided again on
 *    the cycle 24 Aug - 23 Sep. That one-time recount is a data migration
 *    (`RecountEarnedLeaveOnPayrollCycle`). Month 8 is not re-decided: the
 *    recount starts on 24 Aug.
 *  - The decision is made when PAYROLL IS CALCULATED for the month
 *    (`CalculatePayroll` calls this with `$redecide`), not by a job on the
 *    24th, because the attendance is imported at the end of the month and the
 *    24th came too early for it. A recalculation re-decides from the
 *    attendance as it stands then: a changed outcome reverses the previous
 *    grant with an offsetting entry, or grants afresh. Once the month's
 *    payroll is Approved, Disbursed or Locked the decision is fixed.
 *
 * ⚠ A cycle that has not ended yet is refused. Its lates are not all in.
 *
 * ⚠ The thresholds and the rate are policy data on the company-default
 * attendance policy, so HR revises them on the same screen as the ladders and
 * the change is versioned the same way. The WINDOWS are read per employee
 * though: office and retail name their bands differently, and "ordinary" means
 * whichever band each employee's own policy lists first.
 *
 * Idempotent twice over: the decision row is unique per employee, month and
 * basis, and every ledger entry carries a once-key. A rerun that finds the
 * same outcome writes nothing.
 */
class AccrueMonthlyEarnedLeave
{
    /**
     * @return array{granted: int, withheld: int, skipped: int, changed: int, frozen: bool, days: float, reason: ?string, rows: array<int, array<string, mixed>>}
     */
    public function handle(int $year, int $month, bool $dryRun = false, bool $redecide = false): array
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
        $frozen = $this->isFrozen($year, $month);

        $granted = 0;
        $withheld = 0;
        $skipped = 0;
        $changed = 0;
        $rows = [];

        foreach ($employees as $employee) {
            $current = EarnedLeaveAccrual::where('employee_id', $employee->id)
                ->forMonth($year, $month)
                ->current()
                ->first();

            // Decided already and not open to a re-decision: a calendar row,
            // a month whose payroll is approved, or a plain run of the job.
            if ($current !== null && ! ($redecide && ! $frozen && $current->basis === EarnedLeaveAccrual::BASIS_CYCLE)) {
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
            $days = $reason === null ? $accrual->daysPerMonth : 0.0;
            $moved = $current !== null && round((float) $current->days_granted, 2) !== round($days, 2);

            $rows[] = [
                'employee_code' => $employee->employee_code,
                'employee' => $employee->fullName(),
                'ordinary_lates' => $ordinary,
                'serious_lates' => $serious,
                'days' => $days,
                'withheld_reason' => $reason,
                'changed' => $moved,
                'previous_days' => $current === null ? null : (float) $current->days_granted,
            ];

            $reason === null ? $granted++ : $withheld++;
            $changed += $moved ? 1 : 0;

            if ($dryRun) {
                continue;
            }

            $current === null
                ? $this->record($employee, $earned, $year, $month, $monthStart, $monthEnd, $days, $ordinary, $serious, $reason)
                : $this->redecide($current, $employee, $earned, $monthStart, $monthEnd, $days, $ordinary, $serious, $reason);
        }

        return [
            'granted' => $granted,
            'withheld' => $withheld,
            'skipped' => $skipped,
            'changed' => $changed,
            'frozen' => $frozen,
            'days' => round($granted * $accrual->daysPerMonth, 2),
            'reason' => null,
            'rows' => $rows,
        ];
    }

    /**
     * ⭐ 27 Oct 2026: a decision stops counting. Its grant, if it still stands,
     * is answered by an equal and opposite entry, and the row is marked
     * superseded with the reason. Nothing is deleted: the ledger is
     * append-only and the decision row is the record of what was decided.
     *
     * Idempotent: a superseded row is left alone, and the reversal is keyed
     * on the grant it reverses.
     */
    public function supersede(EarnedLeaveAccrual $decision, string $reason): void
    {
        if ($decision->superseded_at !== null) {
            return;
        }

        DB::transaction(function () use ($decision, $reason) {
            $this->reverseGrant($decision, $reason);

            $decision->update([
                'leave_ledger_entry_id' => null,
                'superseded_at' => now(),
                'superseded_reason' => mb_substr($reason, 0, 250),
            ]);
        });
    }

    /**
     * ⭐ 27 Oct 2026 (Joy): the decision is fixed once the month's payroll is
     * approved. Read off every version of the period, so a locked payroll
     * reopened by an unlock (its locked version stays Locked) keeps the
     * decision it was paid with, while an approved one reopened as a new
     * version (the old one is Cancelled) can be decided again.
     */
    public function isFrozen(int $year, int $month): bool
    {
        return PayrollRun::query()
            ->whereHas('period', fn ($query) => $query->where('year', $year)->where('month', $month))
            ->whereIn('status', [PayrollStatus::Approved, PayrollStatus::Disbursed, PayrollStatus::Locked])
            ->exists();
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
        float $days,
        int $ordinary,
        int $serious,
        ?string $reason,
    ): void {
        DB::transaction(function () use ($employee, $earned, $year, $month, $monthStart, $monthEnd, $days, $ordinary, $serious, $reason) {
            $decision = EarnedLeaveAccrual::create([
                'employee_id' => $employee->id,
                'year' => $year,
                'month' => $month,
                'basis' => EarnedLeaveAccrual::BASIS_CYCLE,
                'days_granted' => $days,
                'withheld_reason' => $reason,
                'ordinary_lates' => $ordinary,
                'serious_lates' => $serious,
            ]);

            // Withheld months write no ledger entry - a balance may only move
            // for days that were actually earned. The decision row is where
            // the reason lives.
            if ($reason === null) {
                $this->grant($decision, $earned, $monthStart, $monthEnd, $days);
            }
        });
    }

    /**
     * ⭐ 27 Oct 2026: payroll for the month was calculated again. The row is
     * brought up to the attendance as it stands now, and the ledger follows
     * only when the days changed: the standing grant is reversed, and a
     * granted outcome is posted afresh under its own key.
     */
    private function redecide(
        EarnedLeaveAccrual $decision,
        Employee $employee,
        LeaveType $earned,
        Carbon $monthStart,
        Carbon $monthEnd,
        float $days,
        int $ordinary,
        int $serious,
        ?string $reason,
    ): void {
        $unchanged = round((float) $decision->days_granted, 2) === round($days, 2)
            && $decision->withheld_reason === $reason
            && $decision->ordinary_lates === $ordinary
            && $decision->serious_lates === $serious;

        if ($unchanged) {
            return;
        }

        DB::transaction(function () use ($decision, $earned, $monthStart, $monthEnd, $days, $ordinary, $serious, $reason) {
            $daysMoved = round((float) $decision->days_granted, 2) !== round($days, 2);

            if ($daysMoved) {
                $this->reverseGrant($decision, sprintf(
                    'Earned leave for %s taken back: payroll was calculated again and the month is now %s.',
                    PayrollCycle::describe($decision->year, $decision->month),
                    $reason === null ? 'granted at a different rate' : 'withheld. '.$reason,
                ));
                $decision->leave_ledger_entry_id = null;
            }

            $decision->fill([
                'days_granted' => $days,
                'withheld_reason' => $reason,
                'ordinary_lates' => $ordinary,
                'serious_lates' => $serious,
            ])->save();

            if ($daysMoved && $reason === null) {
                $this->grant($decision, $earned, $monthStart, $monthEnd, $days);
            }
        });
    }

    /**
     * ⚠ The cycle grants carry their own once-key family. The calendar grant
     * for 2026 month 9 already holds `el_accrual:2026:9:{emp}`, and the cycle
     * decision for the same label must be able to post next to its reversal.
     * A grant after a reversal takes the next number, so a month that flips
     * back and forth leaves one entry per flip, each posted once.
     */
    private function grant(EarnedLeaveAccrual $decision, LeaveType $earned, Carbon $monthStart, Carbon $monthEnd, float $days): void
    {
        $base = sprintf('el_accrual:cycle:%d:%d:%d', $decision->year, $decision->month, $decision->employee_id);
        $earlier = LeaveLedgerEntry::where(fn ($query) => $query
            ->where('source_key', $base)
            ->orWhere('source_key', 'like', $base.':%'))
            ->count();

        $entry = LeaveLedgerEntry::once($earlier === 0 ? $base : $base.':'.($earlier + 1), [
            'employee_id' => $decision->employee_id,
            'leave_type_id' => $earned->id,
            'year' => $decision->year,
            'entry_type' => LeaveLedgerEntryType::Grant,
            'days' => $days,
            'occurred_on' => $monthEnd->toDateString(),
            'note' => sprintf(
                'Earned leave for %s (%s to %s): %s day(s). %d ordinary and %d serious lates, within the allowance.',
                $monthEnd->format('F Y'),
                $monthStart->format('j M'),
                $monthEnd->format('j M Y'),
                rtrim(rtrim(number_format($days, 2), '0'), '.'),
                $decision->ordinary_lates,
                $decision->serious_lates,
            ),
            'reference_type' => $decision->getMorphClass(),
            'reference_id' => $decision->id,
            'created_by' => Auth::id(),
        ]);

        $decision->update(['leave_ledger_entry_id' => $entry?->id]);
    }

    /**
     * An equal and opposite entry for the grant that still stands, dated the
     * day it is taken back. Filed as an adjustment, so the leave register
     * reads it off what the employee GOT, not off what they used.
     */
    private function reverseGrant(EarnedLeaveAccrual $decision, string $note): void
    {
        $grant = $decision->leave_ledger_entry_id === null
            ? null
            : LeaveLedgerEntry::find($decision->leave_ledger_entry_id);

        // A calendar grant posted before decisions pointed at their entries
        // is found by the once-key it was posted under.
        if ($grant === null && $decision->basis === EarnedLeaveAccrual::BASIS_CALENDAR && (float) $decision->days_granted > 0) {
            $grant = LeaveLedgerEntry::where('source_key', sprintf(
                'el_accrual:%d:%d:%d', $decision->year, $decision->month, $decision->employee_id,
            ))->first();
        }

        if ($grant === null) {
            return;
        }

        LeaveLedgerEntry::once('el_accrual_reversal:'.$grant->id, [
            'employee_id' => $grant->employee_id,
            'leave_type_id' => $grant->leave_type_id,
            'year' => $grant->year,
            'entry_type' => LeaveLedgerEntryType::Adjustment,
            'days' => -1 * (float) $grant->days,
            'occurred_on' => Carbon::today()->toDateString(),
            'note' => mb_substr($note, 0, 250),
            'reference_type' => $decision->getMorphClass(),
            'reference_id' => $decision->id,
            'created_by' => Auth::id(),
        ]);
    }

    /**
     * @return array{granted: int, withheld: int, skipped: int, changed: int, frozen: bool, days: float, reason: ?string, rows: array<int, array<string, mixed>>}
     */
    private function empty(string $reason): array
    {
        return ['granted' => 0, 'withheld' => 0, 'skipped' => 0, 'changed' => 0, 'frozen' => false, 'days' => 0.0, 'reason' => $reason, 'rows' => []];
    }
}
