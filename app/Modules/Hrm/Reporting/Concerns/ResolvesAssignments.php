<?php

namespace App\Modules\Hrm\Reporting\Concerns;

use App\Modules\Hrm\Reporting\ReportParameters;
use Illuminate\Database\Eloquent\Builder;

/**
 * The as-at assignment join, in one place.
 *
 * Every report that names an employee's office or department has to answer the
 * same question — *which* office and department, on which date — and the answer
 * is always the same one: **the assignment in force on the date the report is
 * about, never today's.** A June report must file people where they worked in
 * June; joining the current assignment would refile a transferred employee's
 * whole history under their new department, and every row would still look
 * perfectly plausible.
 *
 * By the sixth report the join had been written out eight times. That is the
 * point at which a repeated correctness rule stops being a coincidence and
 * becomes a seam: the ninth report will get it right by inheriting it rather
 * than by its author remembering.
 *
 * ⚠ **Two traps are baked in here so no caller can miss them.**
 *
 *  1. **Dates are wrapped in DATE() when both sides are columns.** A `date`-cast
 *     column stores `2026-06-15 00:00:00` while a value written through the
 *     query builder stores bare `2026-06-15`, and comparing them as strings
 *     makes the boundary date silently fail to match. This has now bitten the
 *     project four times.
 *  2. **`Employee` has no `BelongsToBusiness`.** A report spined on `employees`
 *     rather than on a business-scoped model gets no tenancy from a global
 *     scope, and a global scope never applies to a joined table anyway — so
 *     `joinAssignmentsAsAt()` takes the business explicitly when it is the spine.
 */
trait ResolvesAssignments
{
    /**
     * Join the primary assignment in force on a fixed date, plus its office and
     * department names.
     *
     * @param  string  $employeeColumn  the spine's employee foreign key
     * @param  string  $asAt  the date the report is about, as Y-m-d
     */
    protected function joinAssignmentAsAt(Builder $query, string $employeeColumn, string $asAt): Builder
    {
        return $query
            ->leftJoin('employee_assignments', function ($join) use ($employeeColumn, $asAt) {
                $join->on('employee_assignments.employee_id', '=', $employeeColumn)
                    ->where('employee_assignments.is_primary', '=', true)
                    ->whereDate('employee_assignments.effective_from', '<=', $asAt)
                    ->where(function ($inner) use ($asAt) {
                        $inner->whereNull('employee_assignments.effective_to')
                            ->orWhereDate('employee_assignments.effective_to', '>=', $asAt);
                    })
                    // ⚠ One row per spine row, whatever the assignment data
                    // holds: two live primary assignments would otherwise print
                    // and total a person twice. The latest one wins.
                    ->whereRaw(
                        "employee_assignments.id = (select ea2.id from employee_assignments ea2 where ea2.employee_id = {$employeeColumn} and ea2.is_primary = 1 and date(ea2.effective_from) <= ? and (ea2.effective_to is null or date(ea2.effective_to) >= ?) order by ea2.effective_from desc, ea2.id desc limit 1)",
                        [$asAt, $asAt],
                    );
            })
            ->leftJoin('offices', 'offices.id', '=', 'employee_assignments.office_id')
            ->leftJoin('departments', 'departments.id', '=', 'employee_assignments.department_id');
    }

    /**
     * The office/department columns every report selects alongside its own.
     *
     * @return array<int, string>
     */
    protected function assignmentColumns(): array
    {
        return [
            'offices.name as office_name',
            'departments.name as department_name',
        ];
    }

    /**
     * Apply the office and department filters, which are declared by almost
     * every definition and filtered identically by all of them.
     *
     * @param  ReportParameters  $parameters
     */
    protected function filterByAssignment(Builder $query, $parameters): Builder
    {
        return $query
            ->when(
                $parameters->int('office_id'),
                fn (Builder $q, int $id) => $q->where('employee_assignments.office_id', $id),
            )
            ->when(
                $parameters->int('department_id'),
                fn (Builder $q, int $id) => $q->where('employee_assignments.department_id', $id),
            );
    }
}
