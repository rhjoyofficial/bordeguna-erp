<?php

namespace App\Modules\Hrm\Reporting\Definitions;

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
 * Meal break compliance — per employee, per month (C24, MEAL-BREAK-ARCHITECTURE.md).
 *
 * The question this answers is not "who took a long lunch once" but "who does
 * this repeatedly": punch discipline, fallback days by cause, average and
 * longest break, overruns and late returns with their minutes. It is the input
 * HR uses to raise a show-cause — the meal engine itself never deducts and
 * never escalates (C24), so this report is where the counting lives.
 *
 * Aggregated in PHP rather than SQL: the meal detail lives in the `calculation`
 * JSON and the flags in a JSON column, and nine employees times thirty-one days
 * is not a dataset worth a dialect-specific JSON query for.
 */
class MealBreakComplianceReport implements ReportDefinition
{
    use CountsRows;

    public function key(): string
    {
        return 'attendance_meal_compliance';
    }

    public function name(): string
    {
        return 'Meal break compliance';
    }

    public function description(): string
    {
        return 'Per employee per month: meal punch discipline, fallback days by cause, average and longest break, overruns and late returns. Only shifts using the meal window mode produce rows.';
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
            ReportParameter::department(),
            ReportParameter::employee(),
        ];
    }

    public function columns(?ReportParameters $parameters = null): array
    {
        return [
            ReportColumn::text('employee_code', 'Code', 12),
            ReportColumn::text('employee_name', 'Employee', 24),
            // The denominator, without which every other column misleads.
            ReportColumn::integer('days_measured', 'Days on meal mode'),
            ReportColumn::integer('complete_pairs', 'Punched fully'),
            ReportColumn::integer('not_punched', 'Not punched'),
            ReportColumn::integer('incomplete', 'One punch'),
            ReportColumn::integer('unreadable', 'Unreadable'),
            ReportColumn::integer('average_break', 'Avg break (min)'),
            ReportColumn::integer('longest_break', 'Longest (min)'),
            ReportColumn::integer('overruns', 'Overruns'),
            ReportColumn::integer('overrun_minutes', 'Overrun (min)'),
            ReportColumn::integer('late_returns', 'Late returns'),
            ReportColumn::integer('outside_window', 'Outside window'),
        ];
    }

    public function totalColumns(?ReportParameters $parameters = null): array
    {
        return ['days_measured', 'complete_pairs', 'not_punched', 'incomplete', 'unreadable', 'overruns', 'overrun_minutes', 'late_returns', 'outside_window'];
    }

    public function rows(ReportParameters $parameters): LazyCollection
    {
        return LazyCollection::make(function () use ($parameters) {
            $byEmployee = [];

            foreach ($this->query($parameters)->cursor() as $day) {
                $meal = $day->calculation['meal'] ?? null;
                $flags = $day->flags ?? [];
                $touched = $meal !== null || array_intersect(DailyAttendance::MEAL_FLAGS, $flags) !== [];

                // A day the resolver never touched (Retail, off days, mode off)
                // is not a compliance fact either way.
                if (! $touched) {
                    continue;
                }

                $key = $day->employee_id;
                $row = $byEmployee[$key] ?? [
                    'employee_code' => $day->employee_code,
                    'employee_name' => trim($day->first_name.' '.$day->last_name),
                    'days_measured' => 0,
                    'complete_pairs' => 0,
                    'not_punched' => 0,
                    'incomplete' => 0,
                    'unreadable' => 0,
                    'breaks' => [],
                    'overruns' => 0,
                    'overrun_minutes' => 0,
                    'late_returns' => 0,
                    'outside_window' => 0,
                ];

                $row['days_measured']++;

                if (($meal['resolved_from'] ?? null) === 'punches') {
                    $row['complete_pairs']++;
                }

                foreach ([
                    'meal_not_punched' => 'not_punched',
                    'meal_punch_incomplete' => 'incomplete',
                    'meal_punches_unreadable' => 'unreadable',
                    'meal_late_return' => 'late_returns',
                    'punch_outside_meal_window' => 'outside_window',
                ] as $flag => $counter) {
                    if (in_array($flag, $flags, true)) {
                        $row[$counter]++;
                    }
                }

                if (in_array('meal_overrun', $flags, true)) {
                    $row['overruns']++;
                    $row['overrun_minutes'] += (int) ($meal['overrun_minutes'] ?? 0);
                }

                if ($meal !== null) {
                    $row['breaks'][] = (int) ($meal['minutes'] ?? 0);
                }

                $byEmployee[$key] = $row;
            }

            ksort($byEmployee);

            foreach ($byEmployee as $row) {
                $breaks = $row['breaks'];
                unset($row['breaks']);

                $row['average_break'] = $breaks === [] ? 0 : (int) round(array_sum($breaks) / count($breaks));
                $row['longest_break'] = $breaks === [] ? 0 : max($breaks);

                yield $row;
            }
        });
    }

    public function estimatedRows(ReportParameters $parameters): int
    {
        // Rows are one per employee after aggregation; the distinct employee
        // count over the month bounds it without materialising anything.
        return $this->query($parameters)->toBase()->distinct()->count('daily_attendances.employee_id');
    }

    public function meta(ReportParameters $parameters): ReportMeta
    {
        $month = $parameters->month();
        $lock = $month === null ? null : AttendancePeriodLock::covering($parameters->monthEnd());

        return new ReportMeta(
            title: 'Meal break compliance',
            subtitle: $month?->format('F Y'),
            periodLocked: $lock !== null,
            lockNote: 'Meal figures never deduct pay and never hold payroll (C24); this report is the input for any show-cause decision.',
        );
    }

    private function query(ReportParameters $parameters): Builder
    {
        $month = $parameters->monthStart();
        $asAt = $parameters->monthEnd()->toDateString();

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
            ->whereDate('daily_attendances.attendance_date', '>=', $month->toDateString())
            ->whereDate('daily_attendances.attendance_date', '<=', $asAt)
            ->when(
                $parameters->int('department_id'),
                fn (Builder $q, int $id) => $q->where('employee_assignments.department_id', $id),
            )
            ->when(
                $parameters->int('employee_id'),
                fn (Builder $q, int $id) => $q->where('daily_attendances.employee_id', $id),
            )
            // ⚠ Deliberately NOT swept into employee_code order with the rest of
            // the application (Joy, 24 Aug 2026): this is a report's own
            // streaming sort, and grouping by the joined id is what keeps one
            // employee's days arriving together without a second join column in
            // the order. The employee_code the reader sees is on every row.
            ->orderBy('daily_attendances.employee_id')
            ->orderBy('daily_attendances.attendance_date')
            ->select([
                'daily_attendances.*',
                'employees.employee_code',
                'employees.first_name',
                'employees.last_name',
            ]);
    }
}
