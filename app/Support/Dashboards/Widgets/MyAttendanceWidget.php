<?php

namespace App\Support\Dashboards\Widgets;

use App\Modules\Hrm\Enums\AttendanceStatus;
use App\Modules\Hrm\Models\DailyAttendance;
use App\Modules\Hrm\Support\PayrollCycle;
use App\Support\Dashboards\WidgetContext;
use Illuminate\Support\Carbon;

/**
 * My attendance today (status, first in, last out) and this payroll period by
 * status, from DailyAttendance.
 */
class MyAttendanceWidget extends EmployeeWidget
{
    public function key(): string
    {
        return 'my_attendance';
    }

    public function title(): string
    {
        return 'My attendance';
    }

    public function description(): ?string
    {
        return 'Today and the current payroll period, as the attendance engine recorded them.';
    }

    public function size(): string
    {
        return 'md';
    }

    public function refreshSeconds(): int
    {
        return 60;
    }

    public function drillDown(WidgetContext $context): ?string
    {
        return '/hrm/clock';
    }

    public function data(WidgetContext $context): array
    {
        $me = $this->me($context);

        if ($me === null) {
            return $this->notLinked();
        }

        $cycle = PayrollCycle::containing(Carbon::today());
        $rows = DailyAttendance::query()
            ->where('employee_id', $me->id)
            ->whereBetween('attendance_date', [$cycle['start']->toDateString(), $cycle['end']->toDateString()])
            ->orderBy('attendance_date')
            ->get();

        $status = fn (DailyAttendance $row) => $row->status instanceof AttendanceStatus ? $row->status : AttendanceStatus::tryFrom((string) $row->status);
        $today = $rows->first(fn (DailyAttendance $row) => $row->attendance_date->isToday());

        $count = fn (AttendanceStatus ...$s) => $rows->filter(fn ($row) => in_array($status($row), $s, true))->count();

        return [
            'kind' => 'breakdown',
            'chart' => 'bar',
            'unit' => 'count',
            'headline' => $today === null
                ? 'Not recorded yet today'
                : ucwords(str_replace('_', ' ', $status($today)?->value ?? 'recorded'))
                    .($today->first_in ? ' since '.$today->first_in->format('g:i a') : '')
                    .($today->last_out ? ', out at '.$today->last_out->format('g:i a') : ''),
            'items' => [
                ['label' => 'Present', 'value' => $count(AttendanceStatus::Present, AttendanceStatus::FieldDuty), 'tone' => 'good'],
                ['label' => 'Late', 'value' => $count(AttendanceStatus::Late, AttendanceStatus::HalfDay), 'tone' => 'warn'],
                ['label' => 'On leave', 'value' => $count(AttendanceStatus::OnLeave)],
                ['label' => 'Absent', 'value' => $count(AttendanceStatus::Absent, AttendanceStatus::MissingPunch), 'tone' => 'bad'],
            ],
            'caption' => 'Payroll period '.$cycle['start']->format('j M').' to '.$cycle['end']->format('j M'),
            'basis' => 'operational',
        ];
    }
}
