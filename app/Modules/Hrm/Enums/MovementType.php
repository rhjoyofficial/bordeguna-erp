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

    public function label(): string
    {
        return match ($this) {
            self::FieldDuty => 'Field duty',
            self::OfficialWork => 'Official work outside the office',
            self::HolidayWork => 'Work on a holiday or off-day',
        };
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
