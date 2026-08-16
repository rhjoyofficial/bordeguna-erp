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
     * Whether arrival time is judged at all.
     *
     * ⚠⚠ **Correction C17 (2026-08-16) — the two rules were fused, and one of
     * them was wrong.** Field duty used to switch off punctuality *and* the
     * pair-closing requirement together, on the reasoning that field staff are
     * measured by outcome. Bor De Guna's internal circular BdG-HQ-OD-26071901
     * (19-07-2026, in force from 20 July) says otherwise, and says it about
     * exactly these people: every member of the Retail Sales Department must be
     * **inside the office by 08:15** and seated for the 08:30 morning ritual.
     * They then leave for the field and do not come back to the device.
     *
     * So the two questions are genuinely separate, and always were:
     *   - "must they close the pair?" — no, they never return (C13)
     *   - "is their arrival judged?"  — yes, by written company order
     *
     * The distinction is now carried by DATA rather than by the enum: a schedule
     * with a shift has a deadline to be judged against, one without does not.
     * That is why the shift-null guard below is the whole rule for field duty,
     * and why assigning the Retail shift to a field-duty schedule is what turns
     * the 08:15 deadline back on.
     */
    public function judgesPunctuality(): bool
    {
        if ($this->shift === null) {
            return false;
        }

        return ! in_array($this->scheduleType, [ScheduleType::Flexible, ScheduleType::Exempt], true);
    }

    /**
     * Whether hours worked mean anything for this day.
     *
     * For someone whose day is one morning punch there is no span to measure, so
     * every hours-based rule (early departure, the half-day floor, the full-day
     * minimum) would fire on a day they worked normally. Judging them on a
     * number that was never captured is worse than not judging them at all.
     */
    public function judgesHoursWorked(): bool
    {
        return ! $this->countsFirstPunchOnly();
    }

    /**
     * The first punch is the whole record: they arrive, they are counted, and
     * anything the device catches afterwards is noise.
     *
     * Joy's instruction of 2026-08-16 for the Retail Sales Officers: "only count
     * their first punch, they are advised to give one punch a day, if there is
     * more then ignore them, because they come to office and go to the field,
     * complete their work outside, come next day, so one punch is enough."
     */
    public function countsFirstPunchOnly(): bool
    {
        return $this->scheduleType === ScheduleType::FieldDuty;
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
