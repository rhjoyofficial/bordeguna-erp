<?php

namespace App\Modules\Hrm\Dashboard\Widgets;

use App\Modules\Hrm\Dashboard\Contracts\DashboardWidget;
use App\Modules\Hrm\Dashboard\DashboardFilters;
use App\Modules\Hrm\Dashboard\WidgetValue;
use App\Modules\Hrm\Models\LeaveRequest;
use App\Modules\Hrm\Reporting\Concerns\ResolvesAssignments;
use App\Modules\Hrm\Reporting\ReportColumnType;
use Illuminate\Database\Eloquent\Builder;

/**
 * RPT-04 #4 — Employees on leave.
 *
 * ⚠⚠ **This tile deliberately does NOT read processed attendance, and it is the
 * one attendance-shaped figure on the dashboard that carries no freshness
 * caveat.** An applied leave request is an *authorisation*: it exists, and it is
 * true, whether or not `ProcessAttendance` has run. So while the present, absent
 * and late tiles are honest about being only as current as the last processing,
 * this one is simply correct — and saying so is more useful than making all four
 * tiles wear the same warning.
 *
 * That also makes it the tile worth reading first on a morning when nothing has
 * been processed: "how many people are off today" still has a real answer.
 *
 * ⚠ **Only an *applied* request counts.** A pending one has authorised nothing,
 * and counting it would tell a manager somebody is away who is expected at work
 * — the same rule `AbsenceAndLeaveReport` applies when it names a leave day.
 *
 * ⚠ **Distinct employees, not requests.** Somebody with two adjoining approved
 * requests spanning the date is one person on leave. Counting rows would inflate
 * the figure exactly when leave is most fragmented.
 *
 * **Not report-backed (C11a), on purpose.** `AbsenceAndLeaveReport` is spined on
 * processed attendance by design, so a tile derived from it would inherit the
 * dependency this one exists to avoid. The drill-down still points there, and
 * the description says the two are counted differently rather than letting
 * somebody discover it by subtracting.
 */
class EmployeesOnLeaveWidget implements DashboardWidget
{
    use ResolvesAssignments;

    public function key(): string
    {
        return 'attendance_on_leave';
    }

    public function title(): string
    {
        return 'On leave';
    }

    public function description(): string
    {
        return 'Employees with approved leave covering this date. Read from the leave register rather than from processed attendance, so it is correct even before attendance has been processed.';
    }

    public function group(): string
    {
        return 'Attendance';
    }

    public function permission(): string
    {
        return 'hrm.attendance.manage';
    }

    public function cacheSeconds(): int
    {
        return (int) config('hrm.dashboard.cache_seconds', 60);
    }

    public function value(DashboardFilters $filters): WidgetValue
    {
        $date = $filters->asAt();

        return new WidgetValue(
            value: $this->query($filters)->distinct()->count('leave_requests.employee_id'),
            type: ReportColumnType::Integer,
            note: 'Approved leave covering '.$date->format('d M Y').'. Does not depend on attendance processing.',
            // Never stale: an authorisation does not wait for a batch job.
            stale: false,
            drilldownReport: 'attendance_absence_leave',
            drilldownParameters: array_filter([
                'month' => $filters->month()->format('Y-m'),
                'kind' => 'leave',
                'office_id' => $filters->officeId(),
                'department_id' => $filters->departmentId(),
            ], fn ($value) => $value !== null),
        );
    }

    private function query(DashboardFilters $filters): Builder
    {
        $date = $filters->asAt()->toDateString();

        $query = LeaveRequest::query()
            ->whereNotNull('leave_requests.applied_at')
            // ⚠ DATE() on both sides — the date-cast trap. `start_date` and
            // `end_date` are cast columns holding `Y-m-d 00:00:00`, and comparing
            // them against a bare date string as text drops the first and last
            // day of every leave request. That would understate this figure on
            // precisely the days somebody's leave begins or ends.
            ->whereRaw('date(leave_requests.start_date) <= ?', [$date])
            ->whereRaw('date(leave_requests.end_date) >= ?', [$date]);

        $query = $this->joinAssignmentAsAt($query, 'leave_requests.employee_id', $date);

        return $this->filterByAssignment($query, $filters->parameters);
    }
}
