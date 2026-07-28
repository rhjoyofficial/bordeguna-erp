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
use Illuminate\Support\LazyCollection;

/**
 * RPT-01 #4 — Absent and leave.
 *
 * Every day somebody was not at work, and whether it was authorised. The two
 * belong on one report because the question behind them is one question: an
 * absence that turns out to be approved leave is not an absence, and the only
 * way to see that is to see them together.
 *
 * ⚠⚠ **The spine is processed attendance, not the leave register, and that is a
 * deliberate boundary rather than a shortcut.** Phase 3's rule — "Attendance
 * provides facts, Payroll calculates money" — has a counterpart here: a leave
 * *request* is an intention, and `DailyAttendance` is what actually happened.
 * Driving this off `leave_requests` would list approved leave for dates that
 * have not been processed yet, including dates in the future, on a report people
 * read as a record of the past.
 *
 * **The consequence, stated plainly:** an approved leave request whose days have
 * not yet been processed does not appear here. That is correct and it is also
 * surprising, so the report says so on its own face rather than leaving someone
 * to discover it.
 *
 * The leave request is joined only to *name* what the day was — type, paid or
 * unpaid, and the reason given. The day itself is still the engine's.
 */
class AbsenceAndLeaveReport implements ReportDefinition
{
    public function key(): string
    {
        return 'attendance_absence_leave';
    }

    public function name(): string
    {
        return 'Absent and leave';
    }

    public function description(): string
    {
        return 'Every processed day of absence or leave, with the leave type and whether it was paid. Approved leave for days not yet processed is not included.';
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
            ReportParameter::employee(),
            ReportParameter::select('kind', 'Show', [
                ['value' => 'all', 'label' => 'Absence and leave'],
                ['value' => 'absent', 'label' => 'Unauthorised absence only'],
                ['value' => 'leave', 'label' => 'Approved leave only'],
            ], default: 'all'),
        ];
    }

    public function columns(?ReportParameters $parameters = null): array
    {
        return [
            ReportColumn::date('attendance_date', 'Date'),
            ReportColumn::text('employee_code', 'Code', 12),
            ReportColumn::text('employee_name', 'Employee', 24),
            ReportColumn::text('department', 'Department', 18),
            ReportColumn::text('status', 'Status', 14),
            ReportColumn::text('leave_type', 'Leave type', 18),
            ReportColumn::boolean('is_paid', 'Paid'),
            ReportColumn::text('reason', 'Reason', 34),
            // POL-06: the engine proposes Absent and a human finalises it. An
            // absence still awaiting that review must not read as settled, since
            // this is the report disciplinary action gets taken from.
            ReportColumn::boolean('is_provisional', 'Provisional'),
        ];
    }

    public function totalColumns(?ReportParameters $parameters = null): array
    {
        // A count is what matters here and the framework already prints it in
        // the totals label; there is no column worth summing.
        return [];
    }

    public function rows(ReportParameters $parameters): LazyCollection
    {
        return LazyCollection::make(function () use ($parameters) {
            foreach ($this->query($parameters)->cursor() as $day) {
                yield [
                    'attendance_date' => $day->attendance_date,
                    'employee_code' => $day->employee_code,
                    'employee_name' => trim($day->first_name.' '.$day->last_name),
                    'department' => $day->department_name,
                    'status' => $day->status?->label(),
                    'leave_type' => $day->leave_type_name,
                    // Null rather than "No" where there is no leave request at
                    // all: an unauthorised absence is not "unpaid leave", and
                    // printing No in that cell would assert something the row
                    // does not know.
                    'is_paid' => $day->leave_type_name === null ? null : (bool) $day->leave_is_paid,
                    'reason' => $day->leave_reason,
                    'is_provisional' => $day->is_provisional,
                ];
            }
        });
    }

    public function estimatedRows(ReportParameters $parameters): int
    {
        return $this->query($parameters)->toBase()->getCountForPagination();
    }

    public function meta(ReportParameters $parameters): ReportMeta
    {
        $month = $parameters->month();
        $lock = $month === null ? null : AttendancePeriodLock::covering($month->copy()->endOfMonth());

        return new ReportMeta(
            title: 'Absent and leave',
            subtitle: $month?->format('F Y'),
            periodLocked: $lock !== null,
            lockNote: 'Based on processed attendance. Approved leave for days that have not been processed yet does not appear.',
        );
    }

    private function query(ReportParameters $parameters): Builder
    {
        $month = $parameters->month() ?? now()->startOfMonth();
        $asAt = $month->copy()->endOfMonth()->toDateString();
        $kind = $parameters->string('kind') ?? 'all';

        $statuses = match ($kind) {
            'absent' => [AttendanceStatus::Absent->value],
            'leave' => [AttendanceStatus::OnLeave->value],
            default => [AttendanceStatus::Absent->value, AttendanceStatus::OnLeave->value],
        };

        return DailyAttendance::query()
            ->join('employees', 'employees.id', '=', 'daily_attendances.employee_id')
            ->leftJoin('employee_assignments', function ($join) use ($asAt) {
                $join->on('employee_assignments.employee_id', '=', 'daily_attendances.employee_id')
                    ->where('employee_assignments.is_primary', '=', true)
                    ->whereDate('employee_assignments.effective_from', '<=', $asAt)
                    ->where(function ($inner) use ($asAt) {
                        $inner->whereNull('employee_assignments.effective_to')
                            ->orWhereDate('employee_assignments.effective_to', '>=', $asAt);
                    });
            })
            ->leftJoin('departments', 'departments.id', '=', 'employee_assignments.department_id')
            // ⚠ Only an *applied* leave request may name a day. A pending or
            // rejected one has not authorised anything, and letting it label the
            // row would turn an unauthorised absence into "Annual leave" on the
            // report someone acts from.
            ->leftJoin('leave_requests', function ($join) {
                // ⚠ DATE() on both sides — see OrganisationSummaryReport. Here
                // the failure would drop the leave type from the first and last
                // day of every leave request, leaving two unexplained absences
                // at the edges of an approved holiday.
                $join->on('leave_requests.employee_id', '=', 'daily_attendances.employee_id')
                    ->whereNotNull('leave_requests.applied_at')
                    ->whereRaw('date(leave_requests.start_date) <= date(daily_attendances.attendance_date)')
                    ->whereRaw('date(leave_requests.end_date) >= date(daily_attendances.attendance_date)');
            })
            ->leftJoin('leave_types', 'leave_types.id', '=', 'leave_requests.leave_type_id')
            ->whereDate('daily_attendances.attendance_date', '>=', $month->toDateString())
            ->whereDate('daily_attendances.attendance_date', '<=', $asAt)
            ->whereIn('daily_attendances.status', $statuses)
            ->when(
                $parameters->int('office_id'),
                fn (Builder $q, int $id) => $q->where('employee_assignments.office_id', $id),
            )
            ->when(
                $parameters->int('department_id'),
                fn (Builder $q, int $id) => $q->where('employee_assignments.department_id', $id),
            )
            ->when(
                $parameters->int('employee_id'),
                fn (Builder $q, int $id) => $q->where('daily_attendances.employee_id', $id),
            )
            ->orderBy('daily_attendances.attendance_date')
            ->orderBy('employees.employee_code')
            ->select([
                'daily_attendances.*',
                'employees.employee_code',
                'employees.first_name',
                'employees.last_name',
                'departments.name as department_name',
                'leave_types.name as leave_type_name',
                'leave_types.is_paid as leave_is_paid',
                'leave_requests.reason as leave_reason',
            ]);
    }
}
