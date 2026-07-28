<?php

namespace App\Modules\Hrm\Dashboard\Widgets;

/**
 * RPT-04 #1a — Present today.
 *
 * ⚠ **"Present" is every worked day, not the `Present` status.** POL-02 keeps a
 * day's status apart from its consequence, so Late, Half day and Field duty are
 * all days somebody was at work. Counting only the `Present` status would report
 * a smaller workforce than actually turned up — and, worse, the present, absent
 * and leave tiles would stop adding up to the headcount, which is the first
 * arithmetic anybody does on a dashboard.
 */
class PresentTodayWidget extends AttendanceDayWidget
{
    public function key(): string
    {
        return 'attendance_present_today';
    }

    public function title(): string
    {
        return 'Present';
    }

    public function description(): string
    {
        return 'Employees with a worked day recorded — present, late, half day or field duty. Only counts days attendance has actually been processed for.';
    }

    protected function status(): string
    {
        return 'present';
    }
}
