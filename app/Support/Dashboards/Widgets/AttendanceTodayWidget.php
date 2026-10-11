<?php

namespace App\Support\Dashboards\Widgets;

use App\Modules\Hrm\Enums\AttendanceStatus;
use App\Modules\Hrm\Models\DailyAttendance;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Support\Dashboards\DashboardKey;
use App\Support\Dashboards\Widget;
use App\Support\Dashboards\WidgetContext;
use Illuminate\Support\Collection;

/**
 * Today's attendance for the whole business, from DailyAttendance (the
 * engine's own output: this widget recomputes nothing). Employees with no row
 * yet show as "Not recorded yet", never as absent.
 */
class AttendanceTodayWidget extends Widget
{
    public function key(): string
    {
        return 'attendance_today';
    }

    public function title(): string
    {
        return 'Attendance today';
    }

    public function description(): ?string
    {
        return 'Everybody in this business today, by status. Not recorded yet means no punch has been processed, not absent.';
    }

    public function category(): string
    {
        return 'People';
    }

    public function dashboards(): array
    {
        return [DashboardKey::Owner];
    }

    public function permission(): ?string
    {
        return 'hrm.attendance.manage';
    }

    public function refreshSeconds(): int
    {
        return 60;
    }

    public function drillDown(WidgetContext $context): ?string
    {
        return '/hrm/attendance/daily';
    }

    /** The employees this widget counts. The team version narrows it. */
    protected function employeeIds(WidgetContext $context): ?Collection
    {
        return null;
    }

    public function data(WidgetContext $context): array
    {
        $ids = $this->employeeIds($context);

        $people = $ids ?? EmployeeAssignment::query()->where('is_primary', true)->active()->pluck('employee_id')->unique()->values();
        $people = Employee::query()->whereIn('id', $people)->where('status', 'active')->pluck('id');

        $byStatus = DailyAttendance::query()
            ->whereIn('employee_id', $people)
            ->whereDate('attendance_date', today())
            ->get(['employee_id', 'status'])
            ->groupBy(fn (DailyAttendance $row) => $row->status instanceof AttendanceStatus ? $row->status->value : (string) $row->status)
            ->map->count();

        $count = fn (string ...$statuses) => (int) collect($statuses)->sum(fn ($s) => $byStatus[$s] ?? 0);
        $recorded = (int) $byStatus->sum();

        $items = [
            ['label' => 'Present', 'value' => $count('present', 'field_duty'), 'tone' => 'good'],
            ['label' => 'Late', 'value' => $count('late', 'half_day'), 'tone' => 'warn'],
            ['label' => 'On leave', 'value' => $count('on_leave')],
            ['label' => 'Absent', 'value' => $count('absent', 'missing_punch'), 'tone' => 'bad'],
            ['label' => 'Off today', 'value' => $count('holiday', 'weekly_off')],
            ['label' => 'Not recorded yet', 'value' => max(0, $people->count() - $recorded)],
        ];

        return [
            'kind' => 'breakdown',
            'chart' => 'donut',
            'unit' => 'count',
            'items' => array_values(array_filter($items, fn ($item) => $item['value'] > 0)),
            'total' => $people->count(),
            'caption' => $people->isEmpty() ? null : $count('present', 'field_duty', 'late', 'half_day').' of '.$people->count().' in today',
            'basis' => 'operational',
            'watch' => ['absent' => $count('absent', 'missing_punch')],
        ];
    }
}
