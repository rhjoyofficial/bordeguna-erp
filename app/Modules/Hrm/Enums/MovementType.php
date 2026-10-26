<?php

namespace App\Modules\Hrm\Enums;

/**
 * Approved presence outside the normal attendance pattern.
 *
 * Field duty and official work are POL-10 ("approved movement = Present,
 * confirmed manually by the manager"); holiday work is POL-09 ("never counts
 * unless explicitly approved"). They share one model because they are the same
 * shape — a dated, reasoned, manager-approved authorisation — and differ only
 * in what the attendance engine does with them once approved.
 */
enum MovementType: string
{
    case FieldDuty = 'field_duty';
    case OfficialWork = 'official_work';
    case HolidayWork = 'holiday_work';
    case WorkFromHome = 'work_from_home';
    // ⭐ 27 Oct 2026 (Joy's HRM queue): leaving before the shift ends, asked
    // for and approved. The movement's from_time is the time of leaving.
    case EarlyLeave = 'early_leave';

    public function label(): string
    {
        return match ($this) {
            self::FieldDuty => 'Field duty',
            self::OfficialWork => 'Official work outside the office',
            self::HolidayWork => 'Work on a holiday or off-day',
            self::WorkFromHome => 'Work from home',
            self::EarlyLeave => 'Early leave',
        };
    }

    /**
     * Whether an applied movement of this type makes the day count as present
     * on its own, with no punches.
     *
     * ⚠⚠ C-WFH-2, and the reason this method exists at all. Field duty and
     * official work ARE the evidence: nobody clocks in from a customer's
     * warehouse. Work from home is the opposite case - Joy's rule is that
     * people at home clock in and out through the web clock exactly as they
     * would in the office, so the movement AUTHORISES and LABELS the day while
     * the punches still decide Present, Late, Absent or MissingPunch. A WFH day
     * with no punches is as unexplained as an office day with no punches, and
     * must stay that way or the cap buys nothing.
     *
     * ⚠ Holiday work answers a different question entirely (POL-09, does work
     * on a non-working day count) and is false here for that reason, not
     * because it is unevidenced.
     */
    public function authorisesPresenceWithoutPunches(): bool
    {
        return match ($this) {
            self::FieldDuty, self::OfficialWork => true,
            // ⚠ Early leave excuses the END of a day the person was present
            // for; it never stands in for the punches that prove they came.
            self::HolidayWork, self::WorkFromHome, self::EarlyLeave => false,
        };
    }

    /**
     * ⚠ Counting WFH comes from APPLIED MOVEMENTS of this type (C-WFH-3), never
     * from a new AttendanceStatus case: that enum is referenced by 17 files and
     * every exhaustive match over it would break at once, each needing a real
     * decision rather than a label.
     */
    public function isWorkFromHome(): bool
    {
        return $this === self::WorkFromHome;
    }

    /**
     * An approved early leave (POL-04): the early departure it covers carries
     * no deduction. Read by DayContextBuilder, never by the presence rule.
     */
    public function isEarlyLeave(): bool
    {
        return $this === self::EarlyLeave;
    }

    /**
     * POL-09 authorisations credit work on a non-working day; POL-10 ones make
     * an ordinary day count as present.
     */
    public function authorisesHolidayWork(): bool
    {
        return $this === self::HolidayWork;
    }
}
