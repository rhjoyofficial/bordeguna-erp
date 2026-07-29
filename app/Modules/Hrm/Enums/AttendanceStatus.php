<?php

namespace App\Modules\Hrm\Enums;

/**
 * What a day resolved to (POL-02/05/06/07/09/10).
 *
 * Status is strictly separate from consequence: POL-02's recommendation and
 * POL-03's worked policy both depend on "Late" being a fact about the day, not
 * a decision about money. AttendanceConsequence carries the latter.
 */
enum AttendanceStatus: string
{
    case Present = 'present';
    case Late = 'late';
    case HalfDay = 'half_day';
    case Absent = 'absent';
    case MissingPunch = 'missing_punch';
    case Holiday = 'holiday';
    case WeeklyOff = 'weekly_off';
    case OnLeave = 'on_leave';
    case FieldDuty = 'field_duty';

    public function label(): string
    {
        return match ($this) {
            self::Present => 'Present',
            self::Late => 'Late',
            self::HalfDay => 'Half day',
            self::Absent => 'Absent',
            self::MissingPunch => 'Missing punch',
            self::Holiday => 'Holiday',
            self::WeeklyOff => 'Weekly off',
            self::OnLeave => 'On leave',
            self::FieldDuty => 'Field duty',
        };
    }

    /**
     * POL-07: a missing punch is a blocking exception, not an assumed Absent or
     * Half-day — payroll holds until an approved correction resolves it.
     */
    public function holdsPayroll(): bool
    {
        return $this === self::MissingPunch;
    }

    /**
     * POL-06: the system may propose Absent, but never finalise it on its own.
     * These statuses stay provisional until a human reviews them.
     */
    public function requiresHumanReview(): bool
    {
        return $this === self::Absent || $this === self::MissingPunch;
    }

    /**
     * Counts as attendance for pay purposes. Holiday/weekly-off are not
     * "worked" days; whether they are *paid* is a Phase-3 payroll question.
     */
    public function isWorkedDay(): bool
    {
        return in_array($this, [self::Present, self::Late, self::HalfDay, self::FieldDuty], true);
    }

    /**
     * Whether the status on its own establishes that the employee turned up
     * (Correction C14).
     *
     * ⚠ **Status alone is not the whole rule.** Approved work on a holiday or a
     * weekly off also counts as reporting for work, but such a day keeps the
     * status `Holiday` or `WeeklyOff` (`DetermineStatus::nonWorkingDay()` never
     * changes it, even when POL-09 approval exists). The complete rule
     * therefore lives on `DailyAttendance::reportedForWork()`, which consults
     * this method and then handles that case. Do not use this method as the
     * eligibility test by itself.
     *
     * `MissingPunch` is excluded: the day is unresolved, PAY-09 holds the whole
     * payslip until a correction is approved, and that correction resolves the
     * day into a real status which is then judged on its own terms.
     */
    public function establishesReportingForWork(): bool
    {
        return $this->isWorkedDay();
    }
}
