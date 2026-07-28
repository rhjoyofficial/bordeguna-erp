<?php

namespace App\Modules\Hrm\Dashboard\Widgets;

/**
 * RPT-04 #1b — Absent today.
 *
 * ⚠ **POL-06: the engine proposes Absent, a human finalises it.** So this figure
 * routinely includes provisional days, and the freshness note says how many —
 * counting them silently would have the dashboard assert something the system
 * explicitly does not. It is also the tile most likely to be acted on
 * disciplinarily, which is the reason to be careful rather than a reason to
 * round the number up.
 */
class AbsentTodayWidget extends AttendanceDayWidget
{
    public function key(): string
    {
        return 'attendance_absent_today';
    }

    public function title(): string
    {
        return 'Absent';
    }

    public function description(): string
    {
        return 'Employees marked absent. The engine proposes an absence and a human confirms it (POL-06), so provisional days are included and flagged.';
    }

    protected function status(): string
    {
        return 'absent';
    }
}
