<?php

namespace App\Modules\Hrm\Attendance;

use App\Modules\Hrm\Attendance\Data\DayContext;
use App\Modules\Hrm\Attendance\Data\PunchRecord;
use App\Modules\Hrm\Attendance\Data\ShiftSpec;
use App\Modules\Hrm\Attendance\Policy\PolicyConfig;
use App\Modules\Hrm\Enums\CorrectionType;
use App\Modules\Hrm\Enums\PunchSource;
use App\Modules\Hrm\Models\AttendanceCorrection;
use App\Modules\Hrm\Models\AttendancePolicy;
use App\Modules\Hrm\Models\AttendancePunch;
use App\Modules\Hrm\Models\DailyAttendance;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\ScheduleAssignment;
use App\Modules\Hrm\Support\ScheduleResolver;
use Illuminate\Support\Carbon;

/**
 * Pipeline stages 1-3 — the only part of processing that touches the database.
 *
 *   1. load the immutable punches for the day
 *   2. resolve the schedule that applied (shift + calendar), via ScheduleResolver
 *   3. resolve holiday / weekly-off context from that schedule's calendar
 *
 * Everything it gathers is frozen into a DayContext, after which the engine
 * runs with no I/O at all. Keeping the boundary here is what makes the rules
 * testable from fixtures and a recalculation reproducible.
 */
class DayContextBuilder
{
    public function __construct(private readonly ScheduleResolver $resolver) {}

    public function build(Employee $employee, Carbon $date, ?AttendancePolicy $policy = null): DayContext
    {
        $date = $date->copy()->startOfDay();

        $policy ??= AttendancePolicy::inForceOn($date);
        $assignment = $this->resolver->resolveFor($employee, $date);

        [$isWeeklyOff, $isHoliday, $holidayName] = $this->calendarContext($assignment, $date);

        return new DayContext(
            employeeId: $employee->id,
            date: $date,
            punches: $this->punches($employee, $date, $assignment),
            shift: $assignment?->shift ? ShiftSpec::forDate($assignment->shift, $date) : null,
            scheduleType: $assignment?->schedule_type,
            isWeeklyOff: $isWeeklyOff,
            isHoliday: $isHoliday,
            holidayName: $holidayName,
            // No policy configured yet still yields a usable, empty rule set
            // rather than a crash — a business that has not set one up can
            // still see hours worked.
            policy: $policy?->toConfig() ?? PolicyConfig::fromArray([]),
            priorOccurrences: DailyAttendance::priorOccurrences($employee->id, $date),
        );
    }

    /**
     * Stage 1. An overnight shift owns punches that land after midnight
     * (SCH-03: the day attributes to its START date), within the shift's
     * configured cross-midnight window — so a 22:00-06:00 shift keeps its 06:03
     * clock-out on the night it began instead of orphaning it on the next day.
     *
     * @return array<int, PunchRecord>
     */
    private function punches(Employee $employee, Carbon $date, ?ScheduleAssignment $assignment): array
    {
        $from = $date->copy()->startOfDay();
        $to = $date->copy()->endOfDay();

        $shift = $assignment?->shift;

        if ($shift?->is_overnight) {
            $spec = ShiftSpec::forDate($shift, $date);
            $to = $spec->end->copy()->addMinutes($spec->crossMidnightWindowMinutes);
        }

        $punches = AttendancePunch::where('employee_id', $employee->id)
            ->whereBetween('punched_at', [$from, $to])
            ->orderBy('punched_at')
            ->orderBy('id')
            ->get()
            ->map(fn (AttendancePunch $punch) => new PunchRecord(
                $punch->id,
                $punch->punched_at,
                $punch->direction,
                $punch->source,
            ))
            ->all();

        return $this->applyCorrections($employee, $date, $punches);
    }

    /**
     * Folds approved corrections into the day's punches (ATT-03 / COR-01).
     *
     * This is the whole integration: corrections are resolved while *gathering*,
     * so the engine downstream sees an ordinary list of punches and needs no
     * concept of a correction at all. An approved missing-out punch simply
     * closes its pair on the next run, which is how a POL-07 payroll hold
     * clears — by the day genuinely no longer being incomplete, rather than by
     * anything overriding the status.
     *
     * The raw rows are never touched; the merge happens in memory.
     *
     * @param  array<int, PunchRecord>  $punches
     * @return array<int, PunchRecord>
     */
    private function applyCorrections(Employee $employee, Carbon $date, array $punches): array
    {
        $corrections = AttendanceCorrection::forDay($employee->id, $date)
            ->applied()
            ->orderBy('id')
            ->get();

        if ($corrections->isEmpty()) {
            return $punches;
        }

        $keyed = collect($punches)->keyBy(fn (PunchRecord $punch) => $punch->id);

        foreach ($corrections as $correction) {
            match ($correction->type) {
                CorrectionType::IgnorePunch => $keyed->forget($correction->punch_id),
                CorrectionType::AmendPunch => $keyed->has($correction->punch_id)
                    ? $keyed->put($correction->punch_id, new PunchRecord(
                        $correction->punch_id,
                        $correction->corrected_at,
                        $correction->direction ?? $keyed->get($correction->punch_id)->direction,
                        PunchSource::Correction,
                    ))
                    : null,
                // Negative ids keep an added punch distinguishable from a real
                // one and collision-free against the punch table.
                CorrectionType::AddPunch => $keyed->put(-$correction->id, new PunchRecord(
                    -$correction->id,
                    $correction->corrected_at,
                    $correction->direction,
                    PunchSource::Correction,
                )),
            };
        }

        return $keyed
            ->filter()
            ->sortBy(fn (PunchRecord $punch) => $punch->at->getTimestamp())
            ->values()
            ->all();
    }

    /**
     * Stage 3. Weekly off comes from the calendar's weekly_off_days (SCH-06,
     * Bangladesh default Friday); a holiday is a dated entry on it (SCH-07).
     *
     * @return array{0: bool, 1: bool, 2: string|null}
     */
    private function calendarContext(?ScheduleAssignment $assignment, Carbon $date): array
    {
        $calendar = $assignment?->calendar;

        if ($calendar === null) {
            return [false, false, null];
        }

        $isWeeklyOff = in_array($date->dayOfWeek, array_map('intval', $calendar->weekly_off_days ?? []), true);

        $holiday = $calendar->holidays()
            ->whereDate('date', $date->toDateString())
            ->first();

        return [$isWeeklyOff, $holiday !== null, $holiday?->name];
    }
}
