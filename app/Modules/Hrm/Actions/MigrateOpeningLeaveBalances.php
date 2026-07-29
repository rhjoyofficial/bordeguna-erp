<?php

namespace App\Modules\Hrm\Actions;

use App\Modules\Hrm\Enums\LeaveLedgerEntryType;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\LeaveLedgerEntry;
use App\Modules\Hrm\Models\LeaveType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * W1 part two — the opening leave balances at go-live.
 *
 * **Joy's business decisions, 2026-07-29:**
 *
 * 1. **Leave resets at go-live.** Everyone starts with their full entitlement
 *    for the leave year, and any leave taken before go-live is **written off
 *    and deliberately not migrated**. An explicit simplification of the
 *    transition, accepted with its cost understood.
 * 2. **Pro-rata for anyone who joined during the leave year.**
 * 3. Earned/Annual continues to carry forward under C6's 2× cap.
 *
 * ⚠⚠ **DECISION 2 CHANGES LEV-02.** `GrantAnnualLeaveQuota`'s docblock states
 * the approved rule as "a full grant, not monthly accrual and **not prorated by
 * joining date**". Pro-rating mid-year joiners is a deliberate departure from
 * that, made knowingly. It is applied HERE, to the one-off opening balance, and
 * `GrantAnnualLeaveQuota` is left untouched — whether future years should also
 * prorate is a separate question that changes every year from now on, and it
 * has not been asked.
 *
 * ⚠ **This is encashable money.** Earned/Annual carries `can_encash`, so an
 * over-granted day is a real cash liability payable on separation. That is why
 * pro-rata rounds **down** to the nearest half day, why preview and commit
 * share one computation, and why nothing here is inferred.
 *
 * ⚠ **Preview and commit CANNOT disagree**, because `plan()` decides everything
 * and `commit()` posts exactly what `plan()` returned. That is C6's lesson from
 * `YearEndLeaveProcessor`, reused rather than re-derived.
 *
 * ⚠ Every posting carries a deterministic `source_key`, so a re-run grants
 * nobody twice. Balances are the ledger's sum and are never stored.
 */
class MigrateOpeningLeaveBalances
{
    /**
     * Work out what every employee should open with. Writes nothing.
     *
     * @return array<int, array<string, mixed>>
     */
    public function plan(int $year): array
    {
        $yearStart = Carbon::create($year, 1, 1)->startOfDay();
        $yearEnd = Carbon::create($year, 12, 31)->startOfDay();
        $daysInYear = $yearStart->diffInDays($yearEnd) + 1;

        $types = LeaveType::active()
            ->where('deducts_balance', true)
            ->where('annual_quota', '>', 0)
            ->orderBy('name')
            ->get();

        $employees = Employee::query()
            ->whereHas('assignments', fn ($q) => $q->where('is_primary', true))
            ->orderBy('employee_code')
            ->get();

        $rows = [];

        foreach ($employees as $employee) {
            $joined = $employee->joining_date === null
                ? null
                : Carbon::parse($employee->joining_date)->startOfDay();

            // Joined before this leave year began → the full entitlement.
            // Joined during it → the share of the year they will actually be
            // employed for.
            $joinedDuringYear = $joined !== null && $joined->gt($yearStart);

            $entitledDays = $joinedDuringYear
                ? $joined->diffInDays($yearEnd) + 1
                : $daysInYear;

            foreach ($types as $type) {
                $full = (float) $type->annual_quota;

                $days = $joinedDuringYear
                    ? $this->roundDownToHalf($full * $entitledDays / $daysInYear)
                    : $full;

                $rows[] = [
                    'employee_id' => $employee->id,
                    'employee_code' => $employee->employee_code,
                    'employee_name' => trim($employee->first_name.' '.$employee->last_name),
                    'leave_type_id' => $type->id,
                    'leave_type' => $type->name,
                    'full_quota' => $full,
                    'prorated' => $joinedDuringYear,
                    'entitled_days' => $entitledDays,
                    'days_in_year' => $daysInYear,
                    'days' => $days,
                    'joined' => $joined?->toDateString(),
                ];
            }
        }

        return $rows;
    }

    /**
     * Post exactly what `plan()` returned.
     *
     * @return array{posted: int, skipped: int, total_days: float}
     */
    public function commit(int $year): array
    {
        $posted = 0;
        $skipped = 0;
        $total = 0.0;

        foreach ($this->plan($year) as $row) {
            if ($row['days'] <= 0) {
                $skipped++;

                continue;
            }

            $entry = LeaveLedgerEntry::once(
                // ⚠ Distinct from GrantAnnualLeaveQuota's `grant:` key on
                // purpose. This is the one-off opening balance, and conflating
                // the two would make the ordinary annual grant silently skip
                // the year we migrated.
                sprintf('opening:%d:%d:%d', $year, $row['employee_id'], $row['leave_type_id']),
                [
                    'employee_id' => $row['employee_id'],
                    'leave_type_id' => $row['leave_type_id'],
                    'year' => $year,
                    'entry_type' => LeaveLedgerEntryType::Grant,
                    'days' => $row['days'],
                    'occurred_on' => Carbon::create($year, 1, 1)->toDateString(),
                    'note' => $row['prorated']
                        ? sprintf(
                            'Opening balance at go-live: %s of %s days, pro-rata for joining %s (%d of %d days of %d).',
                            number_format($row['days'], 1),
                            number_format($row['full_quota'], 1),
                            $row['joined'],
                            $row['entitled_days'],
                            $row['days_in_year'],
                            $year,
                        )
                        : sprintf('Opening balance at go-live: full %s entitlement for %d.', number_format($row['days'], 1), $year),
                    'created_by' => Auth::id(),
                ],
            );

            if ($entry === null) {
                $skipped++;

                continue;
            }

            $posted++;
            $total += $row['days'];
        }

        return ['posted' => $posted, 'skipped' => $skipped, 'total_days' => round($total, 2)];
    }

    /**
     * ⚠ Rounds DOWN, deliberately, and to a half day.
     *
     * Leave is taken in half days, so a balance of 8.37 is not a thing anybody
     * can use. Down rather than nearest because Earned/Annual is encashable:
     * an over-granted day becomes cash the company owes on separation, and the
     * conservative direction is the one that does not quietly create a
     * liability. The difference is at most half a day per person per type.
     */
    private function roundDownToHalf(float $days): float
    {
        return floor($days * 2) / 2;
    }
}
