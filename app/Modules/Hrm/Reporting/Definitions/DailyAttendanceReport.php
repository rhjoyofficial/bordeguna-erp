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
 * RPT-01 #1 — Daily attendance.
 *
 * One row per employee for one date: what the engine decided, why, and what
 * followed from it. This is the first of the two reports built alongside the
 * framework, chosen because it exercises the awkward parts — a lock state, a
 * mixture of column types, and a status whose *consequence* is a separate fact
 * (POL-02: status and consequence were deliberately kept apart, and a report
 * that folded them back together would undo that).
 *
 * **It reads. It recomputes nothing.** `DailyAttendance` is the engine's own
 * output, including its provenance (`policy_version`, the stored `calculation`
 * notes). Re-deriving a status here would create a second opinion about the
 * same day, and the two would eventually differ.
 *
 * **The lock state is reported, not enforced.** An attendance day can change
 * retroactively through a 2c correction — by design — so this report states
 * whether the date sat inside an `AttendancePeriodLock` when it ran. That is
 * what lets two runs that disagree be explained rather than argued about.
 */
class DailyAttendanceReport implements ReportDefinition
{
    use CountsRows;

    public function key(): string
    {
        return 'attendance_daily';
    }

    public function name(): string
    {
        return 'Daily attendance';
    }

    public function description(): string
    {
        return 'Every employee\'s processed attendance for a single date — status, timings, lateness and the consequence the policy applied.';
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
            ReportParameter::date('date', 'Date', default: now()->toDateString()),
            ReportParameter::office(),
            ReportParameter::department(),
            // Added for RPT-04b so the present/absent/late tiles can be
            // report-backed (C11a): the tile counts this report with a status
            // chosen, and the drill-down opens the same rows. Without it the
            // tiles would be aggregates of their own and the "show me these"
            // link would return more rows than the number that offered it.
            ReportParameter::select('status', 'Status', [
                ['value' => 'all', 'label' => 'Every status'],
                ['value' => 'present', 'label' => 'Present (any worked day)'],
                ['value' => 'late', 'label' => 'Late arrival'],
                ['value' => 'absent', 'label' => 'Absent'],
                ['value' => 'on_leave', 'label' => 'On leave'],
                ['value' => 'missing_punch', 'label' => 'Missing punch'],
            ], default: 'all'),
        ];
    }

    public function columns(?ReportParameters $parameters = null): array
    {
        return [
            ReportColumn::text('employee_code', 'Code', 12),
            ReportColumn::text('employee_name', 'Employee', 26),
            ReportColumn::text('office', 'Office', 18),
            ReportColumn::text('department', 'Department', 18),
            ReportColumn::text('status', 'Status', 14),
            ReportColumn::time('first_in', 'First in'),
            ReportColumn::time('last_out', 'Last out'),
            ReportColumn::duration('worked_minutes', 'Worked'),
            ReportColumn::duration('late_minutes', 'Late by'),
            ReportColumn::duration('early_leave_minutes', 'Early by'),
            ReportColumn::text('consequence', 'Consequence', 18),
            // A provisional day is one the engine proposed and no human has
            // confirmed (POL-06/07). Printing it as settled would be a lie of
            // omission on a report someone acts from.
            ReportColumn::boolean('is_provisional', 'Provisional'),
            ReportColumn::boolean('holds_payroll', 'Holds payroll'),
        ];
    }

    public function totalColumns(?ReportParameters $parameters = null): array
    {
        // Worked and late minutes total meaningfully across a day's workforce.
        // Nothing else here does — a sum of statuses is not a number.
        return ['worked_minutes', 'late_minutes', 'early_leave_minutes'];
    }

    public function rows(ReportParameters $parameters): LazyCollection
    {
        return LazyCollection::make(function () use ($parameters) {
            // cursor(), so the whole workforce's day never lands in memory at
            // once. Office and department arrive on the row from the join —
            // calling `currentAssignment()` per row would fire a query per
            // employee and quietly undo the streaming this is built for.
            foreach ($this->query($parameters)->cursor() as $day) {
                yield [
                    'employee_code' => $day->employee_code,
                    'employee_name' => trim($day->first_name.' '.$day->last_name),
                    'office' => $day->office_name,
                    'department' => $day->department_name,
                    'status' => $day->status?->label(),
                    'first_in' => $day->first_in,
                    'last_out' => $day->last_out,
                    'worked_minutes' => $day->worked_minutes,
                    'late_minutes' => $day->late_minutes,
                    'early_leave_minutes' => $day->early_leave_minutes,
                    'consequence' => $day->consequence?->label(),
                    'is_provisional' => $day->is_provisional,
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
        $date = $parameters->date('date');
        // `covering()` is a static finder, not a query scope.
        $lock = $date === null ? null : AttendancePeriodLock::covering($date);

        return new ReportMeta(
            title: 'Daily attendance',
            subtitle: $date?->format('l, d F Y'),
            periodLocked: $lock !== null,
            lockNote: $lock !== null
                ? null
                : 'This date is not inside a locked attendance period — an approved correction can still change these figures.',
        );
    }

    /**
     * ⚠ **The assignment is resolved as at the attendance date, not as at
     * today.** For a report about 15 June, the employee's office and department
     * *on 15 June* is the correct answer — that is the entire point of the P1
     * effective-dated assignment. Joining today's assignment instead would file
     * a transferred employee's whole history under their new department, and
     * the error would be invisible: every row would look plausible.
     */
    /**
     * ⚠⚠ **"Late" is `late_minutes > 0`, NOT `status === Late`** — the same rule
     * `AttendanceExceptionReport` follows, and for the same reason 2b's
     * `ApplyOccurrencePolicy` bug taught it: a late arrival that also produced a
     * short day resolves to `HalfDay`, and a status filter would drop the person
     * who arrived at 11am from the count of who was late.
     *
     * ⚠ **"Present" is every worked day, not the `Present` status.** POL-02 keeps
     * the status and its consequence apart, so Late, HalfDay and FieldDuty are
     * all days somebody was at work. Counting only the `Present` status would
     * report a smaller workforce than turned up, and the daily figures would
     * stop adding to the headcount.
     */
    private function filterByStatus(Builder $query, string $status): Builder
    {
        if ($status === 'late') {
            return $query->where('daily_attendances.late_minutes', '>', 0);
        }

        if ($status === 'present') {
            $worked = array_map(
                fn (AttendanceStatus $s) => $s->value,
                array_filter(AttendanceStatus::cases(), fn (AttendanceStatus $s) => $s->isWorkedDay()),
            );

            return $query->whereIn('daily_attendances.status', $worked);
        }

        return $query->where('daily_attendances.status', $status);
    }

    private function query(ReportParameters $parameters): Builder
    {
        $date = ($parameters->date('date') ?? now())->toDateString();

        return DailyAttendance::query()
            ->join('employees', 'employees.id', '=', 'daily_attendances.employee_id')
            ->leftJoin('employee_assignments', function ($join) use ($date) {
                $join->on('employee_assignments.employee_id', '=', 'daily_attendances.employee_id')
                    ->where('employee_assignments.is_primary', '=', true)
                    ->whereDate('employee_assignments.effective_from', '<=', $date)
                    ->where(function ($inner) use ($date) {
                        $inner->whereNull('employee_assignments.effective_to')
                            ->orWhereDate('employee_assignments.effective_to', '>=', $date);
                    });
            })
            ->leftJoin('offices', 'offices.id', '=', 'employee_assignments.office_id')
            ->leftJoin('departments', 'departments.id', '=', 'employee_assignments.department_id')
            ->when($parameters->date('date'), fn (Builder $q, $d) => $q->onDate($d))
            ->when(
                ($parameters->string('status') ?? 'all') !== 'all',
                fn (Builder $q) => $this->filterByStatus($q, (string) $parameters->string('status')),
            )
            ->when(
                $parameters->int('office_id'),
                fn (Builder $q, int $id) => $q->where('employee_assignments.office_id', $id),
            )
            ->when(
                $parameters->int('department_id'),
                fn (Builder $q, int $id) => $q->where('employee_assignments.department_id', $id),
            )
            // Stable ordering, so two runs of the same report produce the same
            // file rather than the same rows in a different order — the
            // difference between a diffable export and an unusable one.
            ->orderBy('employees.employee_code')
            ->select([
                'daily_attendances.*',
                'employees.employee_code',
                'employees.first_name',
                'employees.last_name',
                'offices.name as office_name',
                'departments.name as department_name',
            ]);
    }
}
