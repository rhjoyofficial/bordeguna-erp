<?php

namespace App\Modules\Hrm\Actions;

use App\Modules\Hrm\Enums\LeaveLedgerEntryType;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\LeaveLedgerEntry;
use App\Modules\Hrm\Models\LeaveType;
use App\Modules\Hrm\Support\LeaveEntitlement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Grants each employee their annual leave quota for a calendar year (LEV-02).
 *
 * ⚠⚠ **LEV-02 WAS REVISED BY JOY ON 2026-07-29.** Its approved wording was "a
 * full grant, not monthly accrual and **not prorated by joining date**", and
 * this action implemented exactly that. It now **pro-rates anyone who joined
 * during the leave year**, so that a joiner is treated identically whether they
 * arrived during the go-live migration or in 2029. The rule itself lives in
 * `LeaveEntitlement` so this action and `MigrateOpeningLeaveBalances` cannot
 * drift apart about the same person's entitlement.
 *
 * ⚠⚠ **AND A REAL BUG WENT WITH IT.** The employee query asked for assignments
 * `activeOn(1 January)`, so **anybody who joined mid-year was skipped entirely**
 * — they got no grant at all, not merely an un-prorated one. Pro-rating without
 * fixing that would have changed nothing for the very people it was meant to
 * serve. The query now asks for an assignment active at any point in the year.
 *
 * Keyed per employee/type/year, so running it twice grants once. That is what
 * lets it sit safely next to the year-end job on a schedule.
 */
class GrantAnnualLeaveQuota
{
    /**
     * @return array{granted: int, skipped: int}
     */
    public function handle(int $year): array
    {
        $types = LeaveType::active()->where('deducts_balance', true)->where('annual_quota', '>', 0)->get();

        // ⚠ Anyone with a primary assignment in effect at ANY point in the
        // year, not merely on 1 January. See the class docblock: the old
        // `activeOn(1 January)` silently excluded every mid-year joiner.
        $yearStart = Carbon::create($year, 1, 1)->startOfDay();
        $yearEnd = Carbon::create($year, 12, 31)->startOfDay();

        $employees = Employee::whereHas('assignments', fn ($query) => $query
            ->where('is_primary', true)
            ->whereDate('effective_from', '<=', $yearEnd->toDateString())
            ->where(fn ($inner) => $inner
                ->whereNull('effective_to')
                ->orWhereDate('effective_to', '>=', $yearStart->toDateString())))
            ->orderBy('id')->get();

        $granted = 0;
        $skipped = 0;

        foreach ($employees as $employee) {
            foreach ($types as $type) {
                $entry = LeaveLedgerEntry::once(
                    sprintf('grant:%d:%d:%d', $year, $employee->id, $type->id),
                    [
                        'employee_id' => $employee->id,
                        'leave_type_id' => $type->id,
                        'year' => $year,
                        'entry_type' => LeaveLedgerEntryType::Grant,
                        'days' => LeaveEntitlement::daysFor((float) $type->annual_quota, $employee->joining_date, $year),
                        'occurred_on' => Carbon::create($year, 1, 1)->toDateString(),
                        'note' => LeaveEntitlement::isProrated($employee->joining_date, $year)
                            ? sprintf(
                                'Annual quota for %d, pro-rata for joining %s (%d of %d days).',
                                $year,
                                (string) $employee->joining_date,
                                LeaveEntitlement::employedDays($employee->joining_date, $year),
                                LeaveEntitlement::daysInYear($year),
                            )
                            : sprintf('Annual quota for %d.', $year),
                        'created_by' => Auth::id(),
                    ],
                );

                $entry === null ? $skipped++ : $granted++;
            }
        }

        return ['granted' => $granted, 'skipped' => $skipped];
    }
}
