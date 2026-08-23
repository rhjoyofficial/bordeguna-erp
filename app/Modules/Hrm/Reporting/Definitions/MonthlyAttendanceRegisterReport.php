<?php

namespace App\Modules\Hrm\Reporting\Definitions;

use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Enums\AttendanceStatus;
use App\Modules\Hrm\Models\AttendancePeriodLock;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Reporting\Concerns\CountsRows;
use App\Modules\Hrm\Reporting\Contracts\ReportDefinition;
use App\Modules\Hrm\Reporting\ReportColumn;
use App\Modules\Hrm\Reporting\ReportMeta;
use App\Modules\Hrm\Reporting\ReportParameter;
use App\Modules\Hrm\Reporting\ReportParameters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\LazyCollection;

/**
 * RPT-01 #2 — Monthly attendance register.
 *
 * The wall-chart: one row per employee, one column per day of the month, a
 * one-letter code in each cell and the month's totals on the end. It is the
 * report HR actually prints and signs, and it is the largest thing the module
 * produces — a thousand employees over a 31-day month is 31,000 cells, which is
 * the size that decided Correction C10a's streaming writer in the first place.
 *
 * **This is the report whose columns depend on its parameters.** June has 30 of
 * them and July has 31, so `columns()` takes the month. The alternative — always
 * printing 31 and leaving three blank every February — would have been a
 * workaround inside a definition, and the framework was supposed to absorb this
 * rather than push it back out.
 *
 * **The spine is `employees`, not `daily_attendances`.** Someone who never
 * punched all month is exactly who a register is consulted about, and a report
 * driven off attendance rows would leave them out entirely: their line would not
 * be blank, it would be absent, and nobody would notice. So the query is a left
 * join and an employee with nothing recorded gets a row of empty cells.
 *
 * **It reads. It recomputes nothing.** Every cell is a status the engine already
 * decided; the summary counts are those statuses tallied, not re-derived.
 */
class MonthlyAttendanceRegisterReport implements ReportDefinition
{
    use CountsRows;

    public function __construct(
        private readonly BusinessContext $context,
    ) {}

    /**
     * The register's alphabet.
     *
     * A one-letter code per status, because a 31-column grid has room for one
     * character and no more. Kept beside the statuses it maps rather than in a
     * blade or a writer, so the legend the report prints and the cells it fills
     * come from the same place and cannot drift.
     */
    private const CODES = [
        AttendanceStatus::Present->value => 'P',
        AttendanceStatus::Late->value => 'L',
        AttendanceStatus::HalfDay->value => '½',
        AttendanceStatus::Absent->value => 'A',
        AttendanceStatus::MissingPunch->value => '?',
        AttendanceStatus::Holiday->value => 'H',
        AttendanceStatus::WeeklyOff->value => 'W',
        AttendanceStatus::OnLeave->value => 'LV',
        AttendanceStatus::FieldDuty->value => 'F',
    ];

    public function key(): string
    {
        return 'attendance_monthly_register';
    }

    public function name(): string
    {
        return 'Monthly attendance register';
    }

    public function description(): string
    {
        return 'The month as a grid — every employee against every day, with each day\'s status as a code, and the month\'s totals alongside.';
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
        $columns = [
            ReportColumn::text('employee_code', 'Code', 12),
            ReportColumn::text('employee_name', 'Employee', 24),
            ReportColumn::text('department', 'Department', 18),
        ];

        // Before a month is chosen the screen is only describing the report, so
        // the longest possible month stands in for the real one.
        //
        // ⚠⚠ Columns are POSITIONS IN THE PAYROLL CYCLE, not days of a calendar
        // month, since the 23 Aug 2026 cycle decision. "August" with start day
        // 24 runs 24 July to 23 August: the first column is the 24th and the
        // 1st sits in the middle. Keying cells by day-of-month looked fine on
        // that example - every day number appears once - and then a
        // February-labelled cycle (24 Jan to 23 Feb) put January's 29th to 31st
        // into columns the February grid did not have. Position is the key;
        // the real day-of-month is only the label.
        if ($parameters === null) {
            for ($day = 1; $day <= 31; $day++) {
                $columns[] = ReportColumn::text('d'.$day, (string) $day, 4);
            }
        } else {
            $start = $parameters->monthStart();
            $end = $parameters->monthEnd();

            $position = 1;
            for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
                $columns[] = ReportColumn::text('d'.$position, (string) $date->day, 4);
                $position++;
            }
        }

        return [
            ...$columns,
            ReportColumn::integer('present_days', 'Present', 9),
            ReportColumn::integer('absent_days', 'Absent', 9),
            ReportColumn::integer('late_days', 'Late', 9),
            ReportColumn::integer('leave_days', 'Leave', 9),
            ReportColumn::duration('worked_minutes', 'Worked'),
        ];
    }

    public function totalColumns(?ReportParameters $parameters = null): array
    {
        // The day columns hold letters, so only the summary totals. A column of
        // P's does not add up to anything.
        return ['present_days', 'absent_days', 'late_days', 'leave_days', 'worked_minutes'];
    }

    /**
     * One row per employee, assembled from a single ordered pass.
     *
     * ⚠ **The streaming property survives here, and it took the awkward shape to
     * keep it.** A register row spans a whole month, so the obvious
     * implementations both break C10a: fetching each employee's days inside the
     * loop is a query per employee, and grouping the month in memory first is
     * the buffering the streaming writer exists to avoid.
     *
     * Instead one query returns every day of the month ordered *by employee*,
     * and this walks it accumulating a single row, emitting it when the employee
     * changes. Exactly one row is ever held. The ordering is therefore not
     * cosmetic — it is what makes the algorithm correct, which is why it is
     * asserted in the query rather than left to chance.
     */
    public function rows(ReportParameters $parameters): LazyCollection
    {
        return LazyCollection::make(function () use ($parameters) {
            $current = null;
            $cycleStart = $parameters->monthStart();

            // `toBase()` keeps the global scopes that were already applied but
            // hands back plain rows — no model hydration for 31,000 cells, and
            // no cast quietly reinterpreting a joined column.
            foreach ($this->query($parameters)->toBase()->cursor() as $record) {
                if ($current !== null && $current['employee_id'] !== $record->employee_id) {
                    yield $this->emit($current);
                    $current = null;
                }

                $current ??= $this->blank($record, $parameters);

                // A left join gives one all-null row for an employee with
                // nothing recorded; their line is the blank one above.
                if ($record->attendance_date === null) {
                    continue;
                }

                $this->fill($current, $record, $cycleStart);
            }

            if ($current !== null) {
                yield $this->emit($current);
            }
        });
    }

    public function estimatedRows(ReportParameters $parameters): int
    {
        // Rows are employees, not attendance days — the row count and the query
        // that produces it deliberately count different things here, and using
        // the day count would over-estimate by a factor of thirty.
        return $this->employees($parameters)->toBase()->getCountForPagination();
    }

    public function meta(ReportParameters $parameters): ReportMeta
    {
        $month = $parameters->month();
        // A register spans a month, so it is locked only if the whole month is.
        // Reporting "locked" when the last week is still open would be the more
        // reassuring answer and the wrong one.
        $lock = $month === null ? null : AttendancePeriodLock::covering($parameters->monthEnd());

        return new ReportMeta(
            title: 'Monthly attendance register',
            subtitle: $month?->format('F Y'),
            periodLocked: $lock !== null,
            lockNote: 'Codes: '.$this->legend(),
        );
    }

    private function legend(): string
    {
        $seen = [];

        foreach (AttendanceStatus::cases() as $status) {
            $seen[] = self::CODES[$status->value].' = '.$status->label();
        }

        return implode(', ', $seen);
    }

    /**
     * @return array<string, mixed>
     */
    private function blank(object $record, ReportParameters $parameters): array
    {
        $row = [
            'employee_id' => $record->employee_id,
            'employee_code' => $record->employee_code,
            'employee_name' => trim($record->first_name.' '.$record->last_name),
            'department' => $record->department_name,
            'present_days' => 0,
            'absent_days' => 0,
            'late_days' => 0,
            'leave_days' => 0,
            'worked_minutes' => 0,
        ];

        // One cell per cycle position, matching columns() exactly.
        $days = (int) $parameters->monthStart()->diffInDays($parameters->monthEnd()) + 1;

        for ($day = 1; $day <= $days; $day++) {
            $row['d'.$day] = '';
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function fill(array &$row, object $record, Carbon $cycleStart): void
    {
        $status = AttendanceStatus::tryFrom((string) $record->day_status);

        if ($status === null) {
            return;
        }

        // ⚠ Position in the cycle, not day-of-month - see columns(). Offset
        // from the cycle start plus one, so 24 July is column d1 in an
        // "August" register that starts on the 24th.
        $day = (int) $cycleStart->diffInDays(Carbon::parse((string) $record->attendance_date)->startOfDay()) + 1;
        $row['d'.$day] = self::CODES[$status->value] ?? '';

        // A late day is a present day that also counts as late — POL-02 keeps
        // the fact and its consequence apart, and a register that counted Late
        // outside Present would make the columns stop adding up to the month.
        if ($status->isWorkedDay()) {
            $row['present_days']++;
        }

        match ($status) {
            AttendanceStatus::Absent => $row['absent_days']++,
            AttendanceStatus::OnLeave => $row['leave_days']++,
            default => null,
        };

        if ($status === AttendanceStatus::Late) {
            $row['late_days']++;
        }

        $row['worked_minutes'] += (int) $record->worked_minutes;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function emit(array $row): array
    {
        unset($row['employee_id']);

        return $row;
    }

    /**
     * The employees the register covers, resolved as at the end of the month —
     * the same as-at rule every other report follows, so a transfer on the 20th
     * files the whole month where the person finished it rather than splitting
     * their row across two departments.
     *
     * ⚠⚠ **The join to `employee_assignments` is an INNER join and carries an
     * explicit `business_id`, and both of those are load-bearing.** Every other
     * report in W3 is spined on a business-scoped model, so tenancy comes free
     * from the global scope. **`Employee` is deliberately central and has no
     * `BelongsToBusiness`** — it is the assignment that belongs to a business.
     * And a global scope applies only to the model being *queried*, never to a
     * table reached through `join()`. So a left join with no business condition
     * would have put every employee of every business on this register, and it
     * would have looked entirely normal: more rows than expected on a report
     * nobody counts by hand.
     */
    private function employees(ReportParameters $parameters): Builder
    {
        $asAt = $parameters->monthEnd()->toDateString();
        $businessId = $this->context->id();

        return Employee::query()
            ->join('employee_assignments', function ($join) use ($asAt, $businessId) {
                $join->on('employee_assignments.employee_id', '=', 'employees.id')
                    ->where('employee_assignments.business_id', '=', $businessId)
                    ->where('employee_assignments.is_primary', '=', true)
                    ->whereDate('employee_assignments.effective_from', '<=', $asAt)
                    ->where(function ($inner) use ($asAt) {
                        $inner->whereNull('employee_assignments.effective_to')
                            ->orWhereDate('employee_assignments.effective_to', '>=', $asAt);
                    });
            })
            ->leftJoin('departments', 'departments.id', '=', 'employee_assignments.department_id')
            ->when(
                $parameters->int('office_id'),
                fn (Builder $q, int $id) => $q->where('employee_assignments.office_id', $id),
            )
            ->when(
                $parameters->int('department_id'),
                fn (Builder $q, int $id) => $q->where('employee_assignments.department_id', $id),
            );
    }

    private function query(ReportParameters $parameters): Builder
    {
        $start = $parameters->monthStart();
        $end = $parameters->monthEnd();

        return $this->employees($parameters)
            ->leftJoin('daily_attendances', function ($join) use ($start, $end) {
                $join->on('daily_attendances.employee_id', '=', 'employees.id')
                    ->whereDate('daily_attendances.attendance_date', '>=', $start->toDateString())
                    ->whereDate('daily_attendances.attendance_date', '<=', $end->toDateString());
            })
            // ⚠ Employee first, then date. The row assembler depends on every
            // one employee's days arriving together and in order; reversing
            // these would silently produce one register row per day.
            ->orderBy('employees.employee_code')
            ->orderBy('daily_attendances.attendance_date')
            ->select([
                'employees.id as employee_id',
                'employees.employee_code',
                'employees.first_name',
                'employees.last_name',
                'departments.name as department_name',
                'daily_attendances.attendance_date',
                // ⚠ Aliased. `employees` has a `status` column of its own
                // (EmployeeStatus), and an unaliased join would have one silently
                // overwrite the other — the day's status read as "active".
                'daily_attendances.status as day_status',
                'daily_attendances.worked_minutes',
            ]);
    }
}
