<?php

namespace App\Modules\Hrm\Dashboard;

use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Enums\AttendanceStatus;
use App\Modules\Hrm\Models\DailyAttendance;
use Illuminate\Support\Carbon;

/**
 * Attendance day by day across the dashboard's range, and the shape of the
 * range as a whole.
 *
 * ⚠⚠ SCOPED EXACTLY LIKE THE TILES, and that is the reason this class exists
 * rather than a query in the controller. If the chart counted every employee
 * while the tiles above it counted one office, the page would show two
 * different truths at once and the chart would be the one people believed,
 * because a picture argues harder than a number. The office and department
 * clauses below are the same ones AttendanceFreshness applies.
 *
 * ⚠ `whereDate` on both bounds. `attendance_date` is date-cast but stored with
 * a time, and a bare comparison drops the first or last day of the range. That
 * defect has now appeared seven times on this project.
 *
 * ⚠ A day inside the range with no rows still appears, at zero. Skipping empty
 * days would compress a fortnight's gap into a continuous line and invent a
 * pattern that did not happen.
 */
class AttendanceTrend
{
    /**
     * @return array{points: array<int, array<string, mixed>>, totals: array<string, int>, days: int}
     */
    public static function for(DashboardFilters $filters, BusinessContext $context): array
    {
        $from = $filters->from()->copy()->startOfDay();
        $to = $filters->to()->copy()->startOfDay();

        // A very long range would draw a bar per day and read as noise. Capped,
        // and the cap is REPORTED rather than silently applied, because a
        // truncated chart that looks complete is the worst of both.
        $maxDays = 62;
        // ⚠ Cast: Carbon returns a FLOAT from diffInDays, so a seven day range
        // arrives as 7.0 and any `=== 7` downstream quietly fails. A day count
        // is a whole number and is stated as one here rather than everywhere
        // that reads it.
        $days = (int) $from->diffInDays($to) + 1;
        $truncated = false;

        if ($days > $maxDays) {
            $from = $to->copy()->subDays($maxDays - 1);
            $days = $maxDays;
            $truncated = true;
        }

        $rows = DailyAttendance::query()
            ->whereDate('attendance_date', '>=', $from->toDateString())
            ->whereDate('attendance_date', '<=', $to->toDateString())
            ->when(
                $filters->departmentId() !== null || $filters->officeId() !== null,
                fn ($query) => $query->whereExists(function ($inner) use ($filters, $context) {
                    $inner->selectRaw('1')
                        ->from('employee_assignments')
                        ->whereColumn('employee_assignments.employee_id', 'daily_attendances.employee_id')
                        ->where('employee_assignments.business_id', $context->id())
                        ->where('employee_assignments.is_primary', true)
                        ->when(
                            $filters->officeId(),
                            fn ($q, $id) => $q->where('employee_assignments.office_id', $id),
                        )
                        ->when(
                            $filters->departmentId(),
                            fn ($q, $id) => $q->where('employee_assignments.department_id', $id),
                        );
                }),
            )
            ->get(['attendance_date', 'status']);

        $byDate = $rows->groupBy(fn (DailyAttendance $d) => $d->attendance_date->toDateString());

        $points = [];

        for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
            $key = $date->toDateString();
            $forDay = $byDate->get($key, collect());

            $points[] = [
                'date' => $key,
                'label' => $date->format('j M'),
                'weekday' => $date->format('D'),
                'present' => $forDay->where('status', AttendanceStatus::Present)->count()
                    + $forDay->where('status', AttendanceStatus::HalfDay)->count()
                    + $forDay->where('status', AttendanceStatus::FieldDuty)->count(),
                'late' => $forDay->where('status', AttendanceStatus::Late)->count(),
                'absent' => $forDay->where('status', AttendanceStatus::Absent)->count(),
                'leave' => $forDay->where('status', AttendanceStatus::OnLeave)->count(),
                'off' => $forDay->where('status', AttendanceStatus::WeeklyOff)->count()
                    + $forDay->where('status', AttendanceStatus::Holiday)->count(),
            ];
        }

        // The shape of the whole range, every status named even at zero so the
        // legend does not move between months.
        $totals = [];

        foreach (AttendanceStatus::cases() as $status) {
            $totals[$status->value] = $rows->where('status', $status)->count();
        }

        return [
            'points' => $points,
            'totals' => $totals,
            'days' => $days,
            'truncated' => $truncated,
            'rows' => $rows->count(),
        ];
    }
}
