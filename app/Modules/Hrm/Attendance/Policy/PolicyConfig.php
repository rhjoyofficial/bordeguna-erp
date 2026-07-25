<?php

namespace App\Modules\Hrm\Attendance\Policy;

/**
 * The whole attendance rule set as data (POL-02..08).
 *
 * Everything the engine decides beyond the shift's own timing lives here, so a
 * policy change is a new versioned row rather than a code change — which is
 * what POL-12 requires when it says changes apply prospectively and never
 * recalculate a closed period.
 */
final readonly class PolicyConfig
{
    /**
     * @param  array<int, GraceWindow>  $graceWindows
     */
    public function __construct(
        public array $graceWindows,
        public int $earlyLeaveGraceMinutes,
        public float $halfDayMaximumHours,
        public float $fullDayMinimumHours,
        public ?int $halfDayArrivalAfterMinutes,
        public bool $countHolidayWorkWithoutApproval,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            array_map(GraceWindow::fromArray(...), $config['grace_windows'] ?? []),
            // Correction C4 fixed the late grace at 15 minutes and POL-04 states
            // the same for early departure.
            (int) ($config['early_leave_grace_minutes'] ?? 15),
            (float) ($config['half_day_maximum_hours'] ?? 4),
            (float) ($config['full_day_minimum_hours'] ?? 8),
            isset($config['half_day_arrival_after_minutes']) ? (int) $config['half_day_arrival_after_minutes'] : null,
            // POL-09: work on a holiday or off-day never counts unless approved.
            (bool) ($config['count_holiday_work_without_approval'] ?? false),
        );
    }

    /**
     * The window an arrival falls into, or null when it is not late at all.
     */
    public function windowFor(int $lateMinutes): ?GraceWindow
    {
        foreach ($this->graceWindows as $window) {
            if ($window->covers($lateMinutes)) {
                return $window;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'grace_windows' => array_map(fn (GraceWindow $w) => $w->toArray(), $this->graceWindows),
            'early_leave_grace_minutes' => $this->earlyLeaveGraceMinutes,
            'half_day_maximum_hours' => $this->halfDayMaximumHours,
            'full_day_minimum_hours' => $this->fullDayMinimumHours,
            'half_day_arrival_after_minutes' => $this->halfDayArrivalAfterMinutes,
            'count_holiday_work_without_approval' => $this->countHolidayWorkWithoutApproval,
        ];
    }
}
