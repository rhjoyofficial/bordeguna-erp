<?php

namespace App\Modules\Hrm\Reporting\Definitions;

use App\Modules\Hrm\Enums\AttendanceStatus;
use App\Modules\Hrm\Models\AttendancePeriodLock;
use App\Modules\Hrm\Models\DailyAttendance;
use App\Modules\Hrm\Reporting\Contracts\ReportDefinition;
use App\Modules\Hrm\Reporting\ReportColumn;
use App\Modules\Hrm\Reporting\ReportMeta;
use App\Modules\Hrm\Reporting\ReportParameter;
use App\Modules\Hrm\Reporting\ReportParameters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * RPT-01 #7 — Business / office / department summary.
 *
 * The month in one page: for each office and department, how many people, how
 * many days present, absent, late and on leave, and how many hours were worked.
 * The report management reads when nobody wants thirty pages of detail.
 *
 * **The only aggregate report in RPT-01, and the aggregation is the database's.**
 * Every other report streams rows and lets `ReportTotals` add them up; here the
 * rows *are* sums, produced by one `GROUP BY`. Walking every attendance day in
 * PHP to build a dozen subtotals would be the same answer at a hundred times the
 * cost, and the cursor still streams — a business with forty departments returns
 * forty rows.
 *
 * ⚠ **A day counts once, under the office and department the employee held on
 * that day.** The assignment is joined per row on the attendance date, not as at
 * the end of the month, because this report is the one where the difference is
 * visible: an employee who transferred mid-month has genuinely spent days in two
 * departments, and filing the whole month under either would move headcount and
 * hours between two subtotals that are supposed to reconcile to the same total.
 *
 * ⚠ **Headcount is `COUNT(DISTINCT employee_id)` over the days present, not a
 * headcount of the department.** Those are different numbers — somebody on leave
 * all month is on the payroll and not in this figure — so the column is named
 * for what it is.
 */
class OrganisationSummaryReport implements ReportDefinition
{
    public function key(): string
    {
        return 'attendance_org_summary';
    }

    public function name(): string
    {
        return 'Office and department summary';
    }

    public function description(): string
    {
        return 'One line per office and department for the month — people who attended, days present, absent, late and on leave, and hours worked.';
    }

    public function group(): string
    {
        return 'Attendance';
    }

    public function permission(): string
    {
        return 'hrm.attendance.manage';
    }

    public function parameters(): array
    {
        return [
            ReportParameter::month(),
            ReportParameter::office(),
            ReportParameter::department(),
        ];
    }

    public function columns(?ReportParameters $parameters = null): array
    {
        return [
            ReportColumn::text('office', 'Office', 22),
            ReportColumn::text('department', 'Department', 22),
            ReportColumn::integer('employees', 'People', 9),
            ReportColumn::integer('present_days', 'Present', 9),
            ReportColumn::integer('late_days', 'Late', 9),
            ReportColumn::integer('absent_days', 'Absent', 9),
            ReportColumn::integer('leave_days', 'Leave', 9),
            ReportColumn::duration('worked_minutes', 'Worked'),
            ReportColumn::duration('late_minutes', 'Late by'),
        ];
    }

    public function totalColumns(?ReportParameters $parameters = null): array
    {
        // ⚠ `employees` is deliberately NOT totalled. Somebody who worked in two
        // departments this month is counted in both rows, so adding the column
        // gives a number larger than the workforce — a plausible-looking figure
        // that is nobody's headcount.
        return ['present_days', 'late_days', 'absent_days', 'leave_days', 'worked_minutes', 'late_minutes'];
    }

    public function rows(ReportParameters $parameters): LazyCollection
    {
        return LazyCollection::make(function () use ($parameters) {
            foreach ($this->query($parameters)->cursor() as $group) {
                yield [
                    // A day with no assignment behind it still has to land
                    // somewhere visible; silently dropping it would make the
                    // subtotals stop reconciling to the daily report.
                    'office' => $group->office_name ?? '(unassigned)',
                    'department' => $group->department_name ?? '(unassigned)',
                    'employees' => $group->employees,
                    'present_days' => $group->present_days,
                    'late_days' => $group->late_days,
                    'absent_days' => $group->absent_days,
                    'leave_days' => $group->leave_days,
                    'worked_minutes' => $group->worked_minutes,
                    'late_minutes' => $group->late_minutes,
                ];
            }
        });
    }

    public function estimatedRows(ReportParameters $parameters): int
    {
        // Rows are office/department groups. There are tens of them at worst, so
        // this never approaches C10e's threshold — which is the honest answer
        // rather than a counted one, and counting a grouped query would cost a
        // subquery over the whole month to learn nothing.
        return 0;
    }

    /**
     * ⚠ **This report must NOT use the `CountsRows` trait**, and this method is
     * why. Its estimate is deliberately 0 — a lie that is safe because C10e only
     * uses it to pick between two correct paths. A dashboard tile *displays* its
     * number (C11a), so delegating here would report a confident zero.
     *
     * Counting a `GROUP BY` needs the grouped query wrapped in a subquery; that
     * is the cost the estimate exists to avoid, and it is worth paying exactly
     * once, here, when somebody actually wants the figure.
     */
    public function countRows(ReportParameters $parameters): int
    {
        return DB::query()->fromSub($this->query($parameters)->toBase(), 'groups')->count();
    }

    public function meta(ReportParameters $parameters): ReportMeta
    {
        $month = $parameters->month();
        $lock = $month === null ? null : AttendancePeriodLock::covering($month->copy()->endOfMonth());

        return new ReportMeta(
            title: 'Office and department summary',
            subtitle: $month?->format('F Y'),
            periodLocked: $lock !== null,
            lockNote: '"People" counts those who attended at least once in each department, so somebody who transferred mid-month appears under both.',
        );
    }

    private function query(ReportParameters $parameters): Builder
    {
        $month = $parameters->month() ?? now()->startOfMonth();
        $end = $month->copy()->endOfMonth()->toDateString();

        $present = implode("','", array_map(
            fn (AttendanceStatus $s) => $s->value,
            array_filter(AttendanceStatus::cases(), fn (AttendanceStatus $s) => $s->isWorkedDay()),
        ));

        return DailyAttendance::query()
            // ⚠ Per-row date join, not as-at-month-end. See the class docblock:
            // this is the report where a mid-month transfer must split.
            // ⚠⚠ **THE DATE-CAST TRAP, FOURTH APPEARANCE — and the first time it
            // has shown up between two *columns* rather than a column and a
            // literal.** A `date` cast writes `2026-06-15 00:00:00`, but a value
            // set through the query builder lands as bare `2026-06-15`, and the
            // two are compared as strings: `'2026-06-15' >= '2026-06-15 00:00:00'`
            // is FALSE. The transfer day silently fell out of both departments
            // and was filed under "(unassigned)" — a plausible-looking row that
            // no total would have contradicted. `whereDate()` cannot help here
            // because both sides are columns, so both are wrapped in DATE()
            // explicitly. Caught by the mid-month transfer test, not by review.
            ->leftJoin('employee_assignments', function ($join) {
                $join->on('employee_assignments.employee_id', '=', 'daily_attendances.employee_id')
                    ->where('employee_assignments.is_primary', '=', true)
                    ->whereRaw('date(employee_assignments.effective_from) <= date(daily_attendances.attendance_date)')
                    ->where(function ($inner) {
                        $inner->whereNull('employee_assignments.effective_to')
                            ->orWhereRaw('date(employee_assignments.effective_to) >= date(daily_attendances.attendance_date)');
                    });
            })
            ->leftJoin('offices', 'offices.id', '=', 'employee_assignments.office_id')
            ->leftJoin('departments', 'departments.id', '=', 'employee_assignments.department_id')
            ->whereDate('daily_attendances.attendance_date', '>=', $month->toDateString())
            ->whereDate('daily_attendances.attendance_date', '<=', $end)
            ->when(
                $parameters->int('office_id'),
                fn (Builder $q, int $id) => $q->where('employee_assignments.office_id', $id),
            )
            ->when(
                $parameters->int('department_id'),
                fn (Builder $q, int $id) => $q->where('employee_assignments.department_id', $id),
            )
            ->groupBy('offices.name', 'departments.name')
            ->orderBy('offices.name')
            ->orderBy('departments.name')
            ->select([
                'offices.name as office_name',
                'departments.name as department_name',
                DB::raw('COUNT(DISTINCT daily_attendances.employee_id) as employees'),
                DB::raw("SUM(CASE WHEN daily_attendances.status IN ('{$present}') THEN 1 ELSE 0 END) as present_days"),
                // Late is counted inside present, exactly as the register counts
                // it — POL-02 keeps the fact and the consequence apart, and the
                // two reports must not disagree about what a late day is.
                DB::raw("SUM(CASE WHEN daily_attendances.status = '".AttendanceStatus::Late->value."' THEN 1 ELSE 0 END) as late_days"),
                DB::raw("SUM(CASE WHEN daily_attendances.status = '".AttendanceStatus::Absent->value."' THEN 1 ELSE 0 END) as absent_days"),
                DB::raw("SUM(CASE WHEN daily_attendances.status = '".AttendanceStatus::OnLeave->value."' THEN 1 ELSE 0 END) as leave_days"),
                DB::raw('SUM(daily_attendances.worked_minutes) as worked_minutes'),
                DB::raw('SUM(daily_attendances.late_minutes) as late_minutes'),
            ]);
    }
}
