<?php

namespace App\Modules\Hrm\Attendance\Data;

use App\Modules\Hrm\Attendance\Policy\PolicyConfig;
use App\Modules\Hrm\Enums\ScheduleType;
use Illuminate\Support\Carbon;

/**
 * Everything one employee-day needs to be judged — the complete, immutable
 * input to the engine (pipeline stages 1-3, gathered by DayContextBuilder).
 *
 * Once this exists the result is fully determined: no stage reads the database,
 * the clock, or anything else. That is what makes a recalculation years later
 * reproduce the original number, and what lets the whole rule set be tested
 * from fixtures with no schema at all.
 */
final readonly class DayContext
{
    /**
     * @param  array<int, PunchRecord>  $punches  ordered by time
     * @param  array<string, int>  $priorOccurrences  grace-window key => count already used this month
     */
    public function __construct(
        public int $employeeId,
        public Carbon $date,
        public array $punches,
        public ?ShiftSpec $shift,
        public ?ScheduleType $scheduleType,
        public bool $isWeeklyOff,
        public bool $isHoliday,
        public ?string $holidayName,
        public PolicyConfig $policy,
        public array $priorOccurrences = [],
        // Phase-2c inputs. Modelled now because POL-06/09/10 all hinge on them,
        // and leaving them out would bake the wrong answer into the engine.
        public bool $hasApprovedLeave = false,
        public bool $hasApprovedMovement = false,
        public bool $hasApprovedHolidayWork = false,
    ) {}

    public function hasPunches(): bool
    {
        return $this->punches !== [];
    }

    public function isNonWorkingDay(): bool
    {
        return $this->isWeeklyOff || $this->isHoliday;
    }

    /**
     * Flexible and field-duty schedules are judged on hours worked and manager
     * confirmation, not punctuality (SCH-01 / POL-10), so lateness and early
     * departure simply do not apply to them.
     */
    public function judgesPunctuality(): bool
    {
        if ($this->shift === null) {
            return false;
        }

        return ! in_array($this->scheduleType, [ScheduleType::Flexible, ScheduleType::FieldDuty, ScheduleType::Exempt], true);
    }

    /**
     * Whether a day must have a *closed* punch pair to count as worked.
     *
     * ⚠⚠ **Correction C13 (2026-07-28) — the rule real operations exposed.**
     * POL-07 makes an unclosed pair a blocking Missing Punch, and that is right
     * for office staff: somebody who clocked in and never out either forgot, or
     * left without saying, and a human should look.
     *
     * **It is wrong for field staff, and wrongly in a way that compounds.** Bor
     * De Guna's Retail Sales Officers give fingerprint at the office each
     * morning and then go out for the day; they do not come back to the device.
     * Under the blanket rule every one of their days becomes a Missing Punch,
     * which holds payroll — so payroll would be blocked for those people *every
     * single month*, needing roughly fifty approved corrections to clear. And
     * because their TA & DA is paid per day present, a held day pays them
     * nothing either.
     *
     * The pairing requirement quietly assumes the person returns to the device.
     * Where the schedule says they will not, the assumption does not hold, and
     * the honest reading of one morning punch is "they came to work" rather
     * than "something went wrong".
     *
     * This is the same principle `judgesPunctuality()` already applies —
     * flexible and field-duty staff are not judged on *when* they arrive —
     * extended to *whether they closed the pair*. Both come from SCH-01 and
     * POL-10 treating those schedules as measured by outcome rather than by
     * the clock.
     */
    public function requiresClosedPunchPair(): bool
    {
        return ! in_array($this->scheduleType, [ScheduleType::Flexible, ScheduleType::FieldDuty, ScheduleType::Exempt], true);
    }

    /**
     * An Exempt schedule goes one step further than the two above: presence is
     * assumed, not evidenced, so an empty punch list on a working day is
     * Present rather than a proposed Absent (and a non-working-day punch never
     * raises a review flag - one provisional day blocks a whole payroll run).
     */
    public function assumesPresence(): bool
    {
        return $this->scheduleType?->assumesPresence() ?? false;
    }

    public function priorOccurrencesFor(string $windowKey): int
    {
        return $this->priorOccurrences[$windowKey] ?? 0;
    }
}
