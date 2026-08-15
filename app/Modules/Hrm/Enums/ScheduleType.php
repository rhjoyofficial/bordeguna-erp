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

    /**
     * Presence assumed. One step past Flexible/FieldDuty on the same axis:
     * they are exempt from punctuality and pair-closing, this is additionally
     * exempt from EVIDENCE - a working day with no punches is still Present.
     * For the people whose work the device cannot see (works from home, or the
     * owner whose day is meetings): no lateness, no absence, punches still
     * recorded when given. Assigned per employee, effective-dated, like any
     * other schedule.
     */
    case Exempt = 'exempt';

    public function label(): string
    {
        return match ($this) {
            self::Fixed => 'Fixed office schedule',
            self::MultiShift => 'Multiple shifts',
            self::Roster => 'Daily/weekly roster',
            self::Flexible => 'Flexible hours',
            self::FieldDuty => 'Field duty',
            self::Exempt => 'Exempt (presence assumed)',
        };
    }

    /**
     * Presence is assumed rather than evidenced: a working day with no punches
     * is Present, and work on a non-working day never raises a review flag.
     */
    public function assumesPresence(): bool
    {
        return $this === self::Exempt;
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
