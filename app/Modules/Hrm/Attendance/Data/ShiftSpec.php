<?php

namespace App\Modules\Hrm\Attendance\Data;

use App\Modules\Hrm\Enums\BreakPolicy;
use App\Modules\Hrm\Models\Shift;
use Illuminate\Support\Carbon;

/**
 * A shift's timing frozen against one specific date — the engine works in
 * absolute instants, never in "09:00" plus assumptions.
 *
 * SCH-03: an overnight shift attributes to its START date, so its end instant
 * lands on the following day.
 */
final readonly class ShiftSpec
{
    public function __construct(
        public string $name,
        public Carbon $start,
        public Carbon $end,
        public int $graceMinutes,
        public int $breakMinutes,
        public BreakPolicy $breakPolicy,
        public ?float $minimumPayableHours,
        public ?int $lateThresholdMinutes,
        public ?int $earlyLeaveThresholdMinutes,
        public bool $isOvernight,
        public int $crossMidnightWindowMinutes,
    ) {}

    public static function forDate(Shift $shift, Carbon $date): self
    {
        $day = $date->copy()->startOfDay();

        $start = $day->copy()->setTimeFromTimeString($shift->timeValue('start_time') ?? '00:00');
        $end = $day->copy()->setTimeFromTimeString($shift->timeValue('end_time') ?? '00:00');

        if ($shift->is_overnight || $end->lte($start)) {
            $end->addDay();
        }

        return new self(
            $shift->name,
            $start,
            $end,
            (int) $shift->grace_minutes,
            (int) $shift->break_minutes,
            $shift->break_policy ?? BreakPolicy::FixedDeduction,
            $shift->minimum_payable_hours !== null ? (float) $shift->minimum_payable_hours : null,
            $shift->late_threshold_minutes,
            $shift->early_leave_threshold_minutes,
            (bool) $shift->is_overnight,
            (int) ($shift->cross_midnight_window_minutes ?? 0),
        );
    }

    public function scheduledMinutes(): int
    {
        return max(0, $this->start->diffInMinutes($this->end));
    }
}
