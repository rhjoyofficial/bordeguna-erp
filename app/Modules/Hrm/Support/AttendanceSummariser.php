<?php

namespace App\Modules\Hrm\Support;

use App\Modules\Hrm\Enums\AttendanceStatus;
use App\Modules\Hrm\Models\DailyAttendance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Per-employee attendance totals over a date range.
 *
 * ⚠⚠ ONE SUMMARISER, TWO SCREENS. The admin summary and an employee's own
 * clock page ask the same question about different people, and the day they
 * answer it differently is the day somebody is told they were late four times
 * on one screen and three on another. Every number below is produced here.
 *
 * ⚠ `whereDate` on both bounds is NOT decoration. `attendance_date` is
 * date-cast but stored with a time component, so a plain `>=` comparison
 * silently falls back to string comparison and drops the first or last day of
 * the range. This project has now hit that defect six times; the seventh is
 * not going to be here.
 *
 * ⚠ A status with no rows still appears, as a zero. A summary that omits the
 * statuses nobody scored reads as "this cannot happen here" rather than "this
 * did not happen this month", and the columns move between months.
 *
 * ℹ Minutes are summed and converted once at the edge. Rounding per row and
 * then adding gives a total that disagrees with its own column.
 */
class AttendanceSummariser
{
    /**
     * @param  Collection<int, DailyAttendance>|null  $days  pre-loaded rows, if the
     *                                                       caller already has them
     * @return array<int, array<string, mixed>> one entry per employee, richest first
     */
    public function forRange(Carbon $from, Carbon $to, ?int $employeeId = null, ?Collection $days = null): array
    {
        $days ??= DailyAttendance::query()
            ->with('employee')
            ->whereDate('attendance_date', '>=', $from->toDateString())
            ->whereDate('attendance_date', '<=', $to->toDateString())
            ->when($employeeId !== null, fn ($q) => $q->where('employee_id', $employeeId))
            ->get();

        return $days
            ->groupBy('employee_id')
            ->map(fn (Collection $rows, $id) => $this->summarise($rows, (int) $id))
            ->sortBy('employee_name')
            ->values()
            ->all();
    }

    /**
     * The same totals for one person, with no grouping ceremony.
     *
     * @return array<string, mixed>
     */
    public function forEmployee(Carbon $from, Carbon $to, int $employeeId): array
    {
        $rows = $this->forRange($from, $to, $employeeId);

        return $rows[0] ?? $this->empty($employeeId);
    }

    /**
     * @param  Collection<int, DailyAttendance>  $rows
     * @return array<string, mixed>
     */
    private function summarise(Collection $rows, int $employeeId): array
    {
        $byStatus = [];

        // Every status, always, including the ones that scored nothing.
        foreach (AttendanceStatus::cases() as $status) {
            $byStatus[$status->value] = $rows->where('status', $status)->count();
        }

        $first = $rows->first();

        return [
            'employee_id' => $employeeId,
            'employee_name' => $first?->employee?->fullName() ?? 'Unknown',
            'employee_code' => $first?->employee?->employee_code,
            'days_recorded' => $rows->count(),
            'by_status' => $byStatus,

            // The headline numbers Joy asked for by name.
            'present' => $byStatus[AttendanceStatus::Present->value],
            // ⚠ Late is `late_minutes > 0` on a worked day, the rule the
            // dashboard trend, the daily report and the Late today tile use.
            // Counting only status Late missed a late half day.
            'late' => $rows
                ->filter(fn (DailyAttendance $d) => $d->status?->isWorkedDay() === true && (int) $d->late_minutes > 0)
                ->count(),
            'absent' => $byStatus[AttendanceStatus::Absent->value],
            'missing_punch' => $byStatus[AttendanceStatus::MissingPunch->value],
            'on_leave' => $byStatus[AttendanceStatus::OnLeave->value],
            'weekly_off' => $byStatus[AttendanceStatus::WeeklyOff->value],
            'holiday' => $byStatus[AttendanceStatus::Holiday->value],
            'half_day' => $byStatus[AttendanceStatus::HalfDay->value],
            'field_duty' => $byStatus[AttendanceStatus::FieldDuty->value],

            // ⚠ "Attended" counts every status where the person actually worked,
            // so Present + Late + Half day + Field duty. Late is not absence and
            // a summary that leaves it out of the worked count invites the
            // reading that a late day was not worked at all.
            'attended' => $byStatus[AttendanceStatus::Present->value]
                + $byStatus[AttendanceStatus::Late->value]
                + $byStatus[AttendanceStatus::HalfDay->value]
                + $byStatus[AttendanceStatus::FieldDuty->value],

            'late_minutes' => (int) $rows->sum('late_minutes'),
            'early_leave_minutes' => (int) $rows->sum('early_leave_minutes'),
            'worked_hours' => round($rows->sum('worked_minutes') / 60, 1),
            'payable_hours' => round($rows->sum('payable_minutes') / 60, 1),

            // The two that stop payroll or mean "not final yet".
            'holds_payroll' => $rows->where('holds_payroll', true)->count(),
            'provisional' => $rows->where('is_provisional', true)->count(),
            'with_consequence' => $rows
                ->filter(fn (DailyAttendance $d) => $d->consequence->deducts() || $d->consequence->warns())
                ->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function empty(int $employeeId): array
    {
        $byStatus = [];

        foreach (AttendanceStatus::cases() as $status) {
            $byStatus[$status->value] = 0;
        }

        return [
            'employee_id' => $employeeId,
            'employee_name' => 'Unknown',
            'employee_code' => null,
            'days_recorded' => 0,
            'by_status' => $byStatus,
            'present' => 0, 'late' => 0, 'absent' => 0, 'missing_punch' => 0,
            'on_leave' => 0, 'weekly_off' => 0, 'holiday' => 0, 'half_day' => 0,
            'field_duty' => 0, 'attended' => 0,
            'late_minutes' => 0, 'early_leave_minutes' => 0,
            'worked_hours' => 0.0, 'payable_hours' => 0.0,
            'holds_payroll' => 0, 'provisional' => 0, 'with_consequence' => 0,
        ];
    }
}
