<?php

namespace App\Modules\Hrm\Support;

use App\Modules\Hrm\Enums\ScheduleScope;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Models\ScheduleAssignment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Answers "which schedule applied to this employee on this date" (SCH-06/07).
 *
 * Assignments are made at whatever level is convenient — the whole business, an
 * office, a department, a section, or one employee — and this picks the most
 * specific one in effect, so a business-wide default needs no per-person
 * duplication and an employee-level exception needs no special data model.
 *
 * The Phase-2b attendance engine calls this for every employee/day it processes,
 * so the batch path resolves a whole roster from two queries rather than N.
 */
class ScheduleResolver
{
    /**
     * The winning assignment for one employee on a date, or null if nothing
     * covers them yet.
     */
    public function resolveFor(Employee $employee, Carbon|string|null $date = null): ?ScheduleAssignment
    {
        return $this->resolveMany(collect([$employee]), $date)[$employee->id] ?? null;
    }

    /**
     * Batch form: employee id => winning assignment (or null).
     *
     * @param  Collection<int, Employee>  $employees
     * @return array<int, ScheduleAssignment|null>
     */
    public function resolveMany(Collection $employees, Carbon|string|null $date = null): array
    {
        $date = Carbon::parse($date ?? now())->toDateString();

        $assignments = ScheduleAssignment::activeOn($date)
            ->with(['shift', 'calendar'])
            ->get()
            // Most specific first, then newest — so the winner for a given scope
            // is simply the first candidate that matches.
            ->sortByDesc(fn (ScheduleAssignment $a) => [
                $a->scope_type->precedence(),
                $a->effective_from->timestamp,
                $a->id,
            ])
            ->values();

        $placements = $this->placements($employees, $date);

        return $employees
            ->mapWithKeys(fn (Employee $employee) => [
                $employee->id => $this->pick($assignments, $employee, $placements[$employee->id] ?? null),
            ])
            ->all();
    }

    /**
     * @param  Collection<int, ScheduleAssignment>  $assignments
     */
    private function pick(Collection $assignments, Employee $employee, ?EmployeeAssignment $placement): ?ScheduleAssignment
    {
        $targets = [
            ScheduleScope::Employee->value => $employee->id,
            ScheduleScope::Section->value => $placement?->section_id,
            ScheduleScope::Department->value => $placement?->department_id,
            ScheduleScope::Office->value => $placement?->office_id,
            ScheduleScope::Business->value => null,
        ];

        return $assignments->first(function (ScheduleAssignment $assignment) use ($targets) {
            $scope = $assignment->scope_type;

            if (! $scope->needsTarget()) {
                return true;
            }

            $target = $targets[$scope->value] ?? null;

            return $target !== null && (int) $assignment->scope_id === (int) $target;
        });
    }

    /**
     * Each employee's primary org placement on the date — that's what makes an
     * office/department/section assignment reach them.
     *
     * @param  Collection<int, Employee>  $employees
     * @return array<int, EmployeeAssignment>
     */
    private function placements(Collection $employees, string $date): array
    {
        return EmployeeAssignment::whereIn('employee_id', $employees->pluck('id'))
            ->where('is_primary', true)
            ->activeOn($date)
            ->orderBy('effective_from')
            ->orderBy('id')
            ->get()
            // Newest wins on the one-day overlap a transfer leaves behind, matching
            // Employee::currentAssignment().
            ->keyBy('employee_id')
            ->all();
    }
}
