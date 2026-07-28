<?php

namespace App\Modules\Hrm\Dashboard\Widgets;

/**
 * RPT-04 #1c — Late today.
 *
 * ⚠⚠ **Late is `late_minutes > 0`, never `status === Late`.** This is the exact
 * bug 2b's `ApplyOccurrencePolicy` was caught by, arriving for the third time:
 * a late arrival that also produced a short day resolves to `HalfDay`, so a
 * status filter drops the person who turned up at eleven from the count of who
 * was late. The report's own `status=late` option applies the minutes rule for
 * this reason, and the drill-down therefore lists exactly the people the tile
 * counted.
 *
 * The consequence a lateness carries is a separate fact again (POL-03's monthly
 * ladder), and it is not folded in here — this tile counts lateness, not
 * punishment.
 */
class LateTodayWidget extends AttendanceDayWidget
{
    public function key(): string
    {
        return 'attendance_late_today';
    }

    public function title(): string
    {
        return 'Late';
    }

    public function description(): string
    {
        return 'Employees who arrived late, counted by minutes late rather than by status — so a late arrival that became a half day is still counted.';
    }

    protected function status(): string
    {
        return 'late';
    }
}
