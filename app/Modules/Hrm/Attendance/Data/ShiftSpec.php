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
        // The meal window (Correction C21), frozen to instants like start/end.
        // Null whenever the mode is not MealWindow — the columns exist on every
        // shift but only carry meaning under that mode.
        public ?Carbon $mealWindowStart = null,
        public ?Carbon $mealWindowEnd = null,
        public ?int $mealStandardMinutes = null,
        public ?int $mealAllowanceMinutes = null,
        public bool $mealDeductWhenNotPunched = true,
        public int $mealShortDayToleranceMinutes = 5,
    ) {}

    public static function forDate(Shift $shift, Carbon $date): self
    {
        $day = $date->copy()->startOfDay();

        $start = $day->copy()->setTimeFromTimeString($shift->timeValue('start_time') ?? '00:00');
        $end = $day->copy()->setTimeFromTimeString($shift->timeValue('end_time') ?? '00:00');

        if ($shift->is_overnight || $end->lte($start)) {
            $end->addDay();
        }

        $mealStart = $shift->timeValue('meal_window_start');
        $mealEnd = $shift->timeValue('meal_window_end');

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
            $mealStart !== null ? $day->copy()->setTimeFromTimeString($mealStart) : null,
            $mealEnd !== null ? $day->copy()->setTimeFromTimeString($mealEnd) : null,
            $shift->meal_standard_minutes,
            $shift->meal_allowance_minutes,
            (bool) ($shift->meal_deduct_when_not_punched ?? true),
            (int) ($shift->meal_short_day_tolerance_minutes ?? 5),
        );
    }

    public function scheduledMinutes(): int
    {
        return max(0, $this->start->diffInMinutes($this->end));
    }

    /**
     * Whether the meal window can actually be applied: the mode alone is not
     * enough, since a half-configured window must fail loudly rather than
     * silently produce zero-break days.
     */
    public function hasCompleteMealConfig(): bool
    {
        return $this->mealWindowStart !== null
            && $this->mealWindowEnd !== null
            && $this->mealStandardMinutes !== null;
    }
}
