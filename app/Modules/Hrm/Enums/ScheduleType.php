<?php

namespace App\Modules\Hrm\Enums;

/**
 * The schedule types SCH-01 requires the system to support ("সবগুলো" — all of
 * them, assigned per employee or group). The type tells the Phase-2b attendance
 * engine how strictly to read the assigned shift: a Fixed schedule is judged
 * against the shift's exact times, while Flexible and Field duty are judged on
 * hours worked / manager confirmation rather than punctuality.
 */
enum ScheduleType: string
{
    case Fixed = 'fixed';
    case MultiShift = 'multi_shift';
    case Roster = 'roster';
    case Flexible = 'flexible';
    case FieldDuty = 'field_duty';

    public function label(): string
    {
        return match ($this) {
            self::Fixed => 'Fixed office schedule',
            self::MultiShift => 'Multiple shifts',
            self::Roster => 'Daily/weekly roster',
            self::Flexible => 'Flexible hours',
            self::FieldDuty => 'Field duty',
        };
    }

    /**
     * Whether a shift must be named. Roster schedules get their shift per-day
     * from the roster itself, and field duty/flexible have no fixed timing to
     * judge against, so those may be assigned a calendar without a shift.
     */
    public function requiresShift(): bool
    {
        return $this === self::Fixed || $this === self::MultiShift;
    }
}
