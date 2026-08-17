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
        public int $duplicateScanWindowSeconds = 60,
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
            // ⚠ Correction C16 (2026-08-16). The biometric device re-reads a
            // finger that lingers, so one arrival can arrive as two punches a
            // second or two apart. Paired strictly in time order that becomes
            // "in at 08:51:25, out at 08:51:26" — a one-second working day that
            // records ZERO minutes worked and orphans the real evening punch.
            // Seen on 7 August days in the first real month, one of them
            // belonging to the only overtime-eligible employee.
            //
            // Punches are immutable (ATT-03), so the duplicate is never deleted:
            // the raw record keeps both scans and the engine collapses them at
            // derivation time, which is also the only layer a rule change can
            // be re-run against.
            //
            // ⚠ Correction C18 (2026-08-17): 300 → 60. Five minutes was safe
            // when a day had two punches; with four, an employee who punches
            // out for lunch and returns three minutes later for a forgotten
            // phone would have the return scan silently discarded. Sixty
            // seconds still absorbs the device's 1-2 second re-reads.
            // ⚠ A policy row that stored 300 explicitly keeps 300 — check the
            // live rows' config at deploy time, per POL-12 as a new version.
            (int) ($config['duplicate_scan_window_seconds'] ?? 60),
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
            'duplicate_scan_window_seconds' => $this->duplicateScanWindowSeconds,
        ];
    }
}
