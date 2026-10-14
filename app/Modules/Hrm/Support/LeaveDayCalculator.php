<?php

namespace App\Modules\Hrm\Support;

use App\Modules\Hrm\Enums\LeaveDayPortion;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\ScheduleAssignment;
use Illuminate\Support\Carbon;

/**
 * Works out which days of a leave range are actually chargeable (LEV-06).
 *
 * LEV-06 answered that holidays and weekly offs inside a leave are excluded
 * from the leave days — so a Thursday-to-Saturday absence over a Friday weekend
 * costs two days, not three. Resolving that needs the employee's own assigned
 * calendar, since SCH-06/07 allow different calendars per office or team.
 *
 * The dates it returns are stored on the request, so the charge stays
 * explainable even if the calendar is later revised.
 */
class LeaveDayCalculator
{
    public function __construct(private readonly ScheduleResolver $resolver) {}

    /**
     * @return array{days: float, dates: array<int, string>}
     */
    public function calculate(Employee $employee, Carbon|string $start, Carbon|string $end, LeaveDayPortion $portion): array
    {
        $start = Carbon::parse($start)->startOfDay();
        $end = Carbon::parse($end)->startOfDay();

        $dates = [];

        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            if ($this->isWorkingDay($employee, $date)) {
                $dates[] = $date->toDateString();
            }
        }

        // A half day only applies to a single-date request; over a range the
        // portion is meaningless and every covered day counts in full.
        $days = $portion->isPartial() && count($dates) === 1
            ? $portion->days()
            : (float) count($dates);

        return ['days' => $days, 'dates' => $dates];
    }

    /**
     * Rule #17 (approved 14 Oct 2026): a leave that crosses the leave-year
     * boundary (New Year; the leave year is the calendar year, the same
     * `year` the ledger and LeaveBalance use) is charged to each year only for
     * its own days. Works from the chargeable dates `calculate()` already
     * produced, so holidays and weekly offs are excluded exactly as before.
     * A half day is always a single date, so it lands whole in its one year.
     *
     * @param  array<int, string>  $dates  the chargeable dates, in order
     * @return array<int, float> days per leave year, earliest year first
     */
    public function daysByYear(array $dates, float $days): array
    {
        $byYear = [];

        foreach ($dates as $date) {
            $year = (int) substr($date, 0, 4);
            $byYear[$year] = ($byYear[$year] ?? 0.0) + 1.0;
        }

        ksort($byYear);

        // One year only: the request's own figure, so a half day stays half.
        if (count($byYear) <= 1) {
            return $byYear === [] ? [] : [array_key_first($byYear) => $days];
        }

        return $byYear;
    }

    /**
     * A day is chargeable unless the employee's assigned calendar says it is a
     * weekly off or a holiday. Deliberately the calendar's own isWorkingDay():
     * attendance and leave charging must never disagree about what a working
     * day is, and a working-day override (a worked Friday) counts here too.
     *
     * ⚠ Known limitation, accepted in the design note: covered_dates are
     * frozen at approval time, so an override created AFTER a leave was
     * approved does not recharge that leave.
     */
    public function isWorkingDay(Employee $employee, Carbon|string $date): bool
    {
        $date = Carbon::parse($date)->startOfDay();
        $assignment = $this->resolver->resolveFor($employee, $date);
        $calendar = $assignment?->calendar;

        if ($calendar === null) {
            return true;
        }

        return $calendar->isWorkingDay($date);
    }

    /**
     * The schedule assignment in force, exposed so callers that already need it
     * do not resolve twice.
     */
    public function assignmentFor(Employee $employee, Carbon|string $date): ?ScheduleAssignment
    {
        return $this->resolver->resolveFor($employee, $date);
    }
}
