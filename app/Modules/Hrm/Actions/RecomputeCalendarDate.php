<?php

namespace App\Modules\Hrm\Actions;

use App\Modules\Hrm\Models\AttendancePeriodLock;
use App\Modules\Hrm\Models\DailyAttendance;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\HolidayCalendar;
use App\Modules\Hrm\Support\FrozenAttendance;
use App\Modules\Hrm\Support\ScheduleResolver;
use Illuminate\Support\Carbon;

/**
 * Re-judges a PAST day after its calendar changed (a holiday or a working-day
 * override added or removed).
 *
 * ⚠⚠ 14 Oct 2026 (audit #20). The engine reads the calendar when it judges a
 * day, and nothing judged the day again afterwards: a holiday declared for
 * yesterday left everybody Absent for yesterday, and the 7-day window then
 * finalised the absence into payroll. Today and later need nothing (the
 * nightly run reads the calendar as it stands).
 *
 * Who: only the employees whose schedule on that date uses THIS calendar,
 * and only days that already exist (like `restOfCycleAfter`, so a calendar
 * edit never fabricates a day for somebody with no record). Forced, because
 * a calendar fact is new evidence for the day the same way a late punch is,
 * and the scheduler finalises days after a week whatever anybody decided.
 *
 * ⚠ NOT when payroll has already used the date: an attendance period lock
 * (C15e), or a payroll run for that period that is approved, paid or locked.
 * Restating a day behind an approved run would change a number nobody will
 * see again; the caller tells the person instead. Since rule #18 (14 Oct
 * 2026) the difference behind an approved run is settled automatically as an
 * adjustment on the next open payroll (SettleFrozenAttendance).
 */
class RecomputeCalendarDate
{
    public function __construct(
        private readonly ProcessAttendance $process,
        private readonly ScheduleResolver $resolver,
        private readonly SettleFrozenAttendance $settle,
    ) {}

    /**
     * @return array{recomputed: int, refused: ?string}
     */
    public function handle(HolidayCalendar $calendar, Carbon|string $date): array
    {
        $date = Carbon::parse($date)->startOfDay();

        if ($date->greaterThanOrEqualTo(Carbon::today())) {
            return ['recomputed' => 0, 'refused' => null];
        }

        if (AttendancePeriodLock::isLocked($date)) {
            return ['recomputed' => 0, 'refused' => "Attendance for {$date->toDateString()} is locked, so it was not recalculated. Unlock the period, or settle the difference through a payroll adjustment."];
        }

        // ⚠⚠ Rule #18 (approved 14 Oct 2026): payroll covering the date is
        // approved, so the day is frozen. It is still not recalculated, but the
        // difference is no longer left to somebody to remember: each affected
        // person's cycle is priced and settled on the next open payroll.
        $frozenRun = FrozenAttendance::runCovering($date);

        $employeeIds = DailyAttendance::query()->onDate($date)->pluck('employee_id')->unique()->values();

        if ($employeeIds->isEmpty()) {
            return ['recomputed' => 0, 'refused' => null];
        }

        $employees = Employee::whereKey($employeeIds)->orderBy('id')->get();
        $schedules = $this->resolver->resolveMany($employees, $date);
        $recomputed = 0;

        $settled = 0;

        foreach ($employees as $employee) {
            if ((int) ($schedules[$employee->id]?->holiday_calendar_id ?? 0) !== (int) $calendar->id) {
                continue;
            }

            if ($frozenRun !== null) {
                if ($this->settle->handle($employee, $frozenRun)['adjustment'] !== null) {
                    $settled++;
                }

                continue;
            }

            if ($this->process->forDay($employee, $date, force: true) !== null) {
                $recomputed++;
            }
        }

        if ($frozenRun !== null) {
            return ['recomputed' => 0, 'refused' => sprintf(
                'Payroll covering %s is already approved, so attendance for that day was not recalculated. %s',
                $date->toDateString(),
                $settled === 0
                    ? 'It makes no money difference to anybody.'
                    : sprintf('The difference was recorded as an adjustment on the next open payroll for %d %s.', $settled, $settled === 1 ? 'person' : 'people'),
            )];
        }

        return ['recomputed' => $recomputed, 'refused' => null];
    }
}
