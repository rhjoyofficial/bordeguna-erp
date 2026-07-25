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

        return ! in_array($this->scheduleType, [ScheduleType::Flexible, ScheduleType::FieldDuty], true);
    }

    public function priorOccurrencesFor(string $windowKey): int
    {
        return $this->priorOccurrences[$windowKey] ?? 0;
    }
}
