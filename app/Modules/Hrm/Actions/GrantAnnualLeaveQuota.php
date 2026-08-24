<?php

namespace App\Modules\Hrm\Actions;

use App\Modules\Hrm\Enums\EmploymentType;
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
     * A probationer's whole annual entitlement (Joy, 24 Aug 2026): sick leave
     * only, a FLAT 3 days - deliberately NOT pro-rated by joining date, because
     * pro-rating 3 days hands a new joiner a balance like 1.4 that nobody can
     * take or explain. Everything else waits for confirmation.
     */
    public const PROBATION_SICK_DAYS = 3.0;

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
            // On probation: the flat sick grant is the whole entitlement.
            if ($employee->employment_type === EmploymentType::Probation) {
                $sick = $types->firstWhere('code', 'sick');

                if ($sick === null) {
                    continue;
                }

                $entry = LeaveLedgerEntry::once(
                    sprintf('grant:%d:%d:%d', $year, $employee->id, $sick->id),
                    [
                        'employee_id' => $employee->id,
                        'leave_type_id' => $sick->id,
                        'year' => $year,
                        'entry_type' => LeaveLedgerEntryType::Grant,
                        'days' => self::PROBATION_SICK_DAYS,
                        'occurred_on' => Carbon::create($year, 1, 1)->toDateString(),
                        'note' => sprintf('Probation quota for %d: sick leave only, flat %.1f days.', $year, self::PROBATION_SICK_DAYS),
                        'created_by' => Auth::id(),
                    ],
                );

                $entry === null ? $skipped++ : $granted++;

                continue;
            }

            // Confirmed DURING this year via the confirm action: their year
            // came in two pieces - the probation sick grant they already hold,
            // and the pro-rated permanent entitlements ConfirmEmployee granted
            // on the day. A re-run of THIS action must not add a third piece:
            // it would key casual under 'grant:' while the confirm grant sits
            // under 'confirm:', and the idempotency that protects every other
            // rerun would wave the duplicate straight through.
            //
            // ⚠ Detected by the confirm entries EXISTING, not by the
            // confirmation_date falling in this year - a historically entered
            // confirmation date (Musa's 2026-03-01, typed in as master data)
            // has no confirm entries, and skipping on the date alone would
            // leave that employee with no grant at all.
            $confirmedByAction = LeaveLedgerEntry::query()
                ->where('employee_id', $employee->id)
                ->where('source_key', 'like', sprintf('confirm:%d:%d:%%', $year, $employee->id))
                ->exists();

            if ($confirmedByAction) {
                continue;
            }

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
