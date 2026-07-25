<?php

namespace App\Modules\Hrm\Enums;

/**
 * Who a schedule assignment applies to (SCH-06/07: "named calendars assigned to
 * offices/teams, with employee exceptions"). The scopes form a precedence
 * ladder — the most specific assignment in effect on a date wins, so a
 * business-wide default can be overridden per office, per department, per
 * section and finally per employee without any of them being rewritten.
 */
enum ScheduleScope: string
{
    case Business = 'business';
    case Office = 'office';
    case Department = 'department';
    case Section = 'section';
    case Employee = 'employee';

    public function label(): string
    {
        return match ($this) {
            self::Business => 'Whole business',
            self::Office => 'Office',
            self::Department => 'Department',
            self::Section => 'Section',
            self::Employee => 'Employee',
        };
    }

    /**
     * Higher wins when more than one assignment covers the same employee/date.
     */
    public function precedence(): int
    {
        return match ($this) {
            self::Business => 0,
            self::Office => 1,
            self::Department => 2,
            self::Section => 3,
            self::Employee => 4,
        };
    }

    /**
     * The business-wide scope is the only one without a target row.
     */
    public function needsTarget(): bool
    {
        return $this !== self::Business;
    }
}
