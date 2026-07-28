<?php

namespace App\Modules\Hrm\Reporting\Definitions;

use App\Modules\Hrm\Enums\AttendanceStatus;
use App\Modules\Hrm\Models\AttendancePeriodLock;
use App\Modules\Hrm\Models\DailyAttendance;
use App\Modules\Hrm\Reporting\Concerns\CountsRows;
use App\Modules\Hrm\Reporting\Contracts\ReportDefinition;
use App\Modules\Hrm\Reporting\ReportColumn;
use App\Modules\Hrm\Reporting\ReportMeta;
use App\Modules\Hrm\Reporting\ReportParameter;
use App\Modules\Hrm\Reporting\ReportParameters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\LazyCollection;

/**
 * RPT-01 #3 — Late / early leaving / missing punch.
 *
 * The exception list: every day that departed from the pattern, with how far it
 * departed and what followed. One report rather than three, because they are
 * asked about together — "what went wrong this month" is a single question — and
 * because a day can be two of them at once.
 *
 * **The report shows the POL-03 ladder, and that is its point.** Each row
 * carries the grace band the day fell in and *which occurrence within the month*
 * it was, so a consequence can be read as the ladder's output rather than as an
 * opinion. This is the report that answers "why did Karim get a warning on the
 * 14th and nothing on the 9th" without anyone opening the engine.
 *
 * ⚠ **Late is filtered on `late_minutes`, never on `status === Late`.** The bug
 * `ApplyOccurrencePolicy` was caught by in 2b is the same one here: a late
 * arrival that also produced a short day resolves to `HalfDay`, and a status
 * filter would drop it from the very report meant to list it. The minutes are
 * the fact; the status is a summary of several facts.
 */
class AttendanceExceptionReport implements ReportDefinition
{
    use CountsRows;

    public function key(): string
    {
        return 'attendance_exceptions';
    }

    public function name(): string
    {
        return 'Late, early leaving and missing punch';
    }

    public function description(): string
    {
        return 'Every day that broke the pattern, with the grace band and occurrence number the policy applied, and the consequence that followed.';
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
            ReportParameter::select('exception', 'Exception type', [
                ['value' => 'all', 'label' => 'All exceptions'],
                ['value' => 'late', 'label' => 'Late arrival only'],
                ['value' => 'early', 'label' => 'Early leaving only'],
                ['value' => 'missing', 'label' => 'Missing punch only'],
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
            ReportColumn::time('first_in', 'First in'),
            ReportColumn::time('last_out', 'Last out'),
            ReportColumn::duration('late_minutes', 'Late by'),
            ReportColumn::duration('early_leave_minutes', 'Early by'),
            // The two POL-03 columns. Without them the consequence column is an
            // assertion; with them it is a derivation anyone can check.
            ReportColumn::text('grace_window_key', 'Grace band', 16),
            ReportColumn::integer('occurrence_number', 'Occurrence', 11),
            ReportColumn::text('consequence', 'Consequence', 18),
            ReportColumn::boolean('holds_payroll', 'Holds payroll'),
        ];
    }

    public function totalColumns(?ReportParameters $parameters = null): array
    {
        return ['late_minutes', 'early_leave_minutes'];
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
                    'first_in' => $day->first_in,
                    'last_out' => $day->last_out,
                    'late_minutes' => $day->late_minutes,
                    'early_leave_minutes' => $day->early_leave_minutes,
                    'grace_window_key' => $day->grace_window_key,
                    'occurrence_number' => $day->occurrence_number,
                    'consequence' => $day->consequence?->label(),
                    'holds_payroll' => $day->holds_payroll,
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
            title: 'Late, early leaving and missing punch',
            subtitle: $month?->format('F Y'),
            periodLocked: $lock !== null,
            lockNote: $lock !== null
                ? null
                : 'This month is not fully locked — an approved correction can still resolve or remove these exceptions.',
        );
    }

    private function query(ReportParameters $parameters): Builder
    {
        $month = $parameters->month() ?? now()->startOfMonth();
        $asAt = $month->copy()->endOfMonth()->toDateString();
        $exception = $parameters->string('exception') ?? 'all';

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
            ->whereDate('daily_attendances.attendance_date', '>=', $month->toDateString())
            ->whereDate('daily_attendances.attendance_date', '<=', $asAt)
            ->where(fn (Builder $q) => $this->exceptions($q, $exception))
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
            // Date first: an exception list is read chronologically, unlike a
            // register, which is read by person.
            ->orderBy('daily_attendances.attendance_date')
            ->orderBy('employees.employee_code')
            ->select([
                'daily_attendances.*',
                'employees.employee_code',
                'employees.first_name',
                'employees.last_name',
                'departments.name as department_name',
            ]);
    }

    /**
     * ⚠ The `all` case is an OR of the three, **not** the absence of a filter.
     * Leaving it unfiltered would return every ordinary present day as well and
     * turn an exception report into a second daily attendance report.
     */
    private function exceptions(Builder $query, string $exception): Builder
    {
        return match ($exception) {
            'late' => $query->where('daily_attendances.late_minutes', '>', 0),
            'early' => $query->where('daily_attendances.early_leave_minutes', '>', 0),
            'missing' => $query->where('daily_attendances.status', AttendanceStatus::MissingPunch->value),
            default => $query->where('daily_attendances.late_minutes', '>', 0)
                ->orWhere('daily_attendances.early_leave_minutes', '>', 0)
                ->orWhere('daily_attendances.status', AttendanceStatus::MissingPunch->value),
        };
    }
}
