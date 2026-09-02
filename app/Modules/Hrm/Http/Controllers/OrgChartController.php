<?php

namespace App\Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hrm\Enums\EmployeeStatus;
use App\Modules\Hrm\Models\Employee;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The organization chart (Module 1, Slice 1): who reports to whom, as a tree.
 *
 * ⚠ The reporting line lives on the current primary ASSIGNMENT
 * (employee_assignments.supervisor_id, written by the Reassign flow since
 * 22 July) - this page only makes the graph that already existed visible.
 *
 * ⚠ Deliberately UNGATED beyond login + business membership. The chart shows
 * names, codes and designations - the company phone book's shape - and the
 * HRM spec files it under the employee directory everyone gets. Salary,
 * contact details and everything sensitive stay behind their own screens.
 */
class OrgChartController extends Controller
{
    public function show(): Response
    {
        // Everyone still employed here - Draft (record incomplete) and
        // OnLeave are real people on the chart; only the separated leave it.
        // ⚠ Employee-code order everywhere an employee list appears.
        $employees = Employee::query()
            ->where('status', '!=', EmployeeStatus::Separated)
            ->whereHas('assignments')
            ->with('assignments.designation')
            ->orderBy('employee_code')
            ->get();

        $byId = $employees->keyBy('id');
        $supervisorOf = $employees->mapWithKeys(fn (Employee $employee) => [
            $employee->id => $employee->currentAssignment()?->supervisor_id,
        ]);

        $node = function (Employee $employee) use (&$node, $employees, $supervisorOf): array {
            return [
                'id' => $employee->id,
                'name' => $employee->fullName(),
                'code' => $employee->employee_code,
                'designation' => $employee->currentAssignment()?->designation?->name,
                'reports' => $employees
                    ->filter(fn (Employee $candidate) => $supervisorOf[$candidate->id] === $employee->id)
                    ->map(fn (Employee $report) => $node($report))
                    ->values()
                    ->all(),
            ];
        };

        // A root is anyone with no supervisor - or whose supervisor is not on
        // the chart (separated, or another business). An active person must
        // never vanish because the person above them left.
        $tree = $employees
            ->filter(fn (Employee $employee) => $supervisorOf[$employee->id] === null
                || ! $byId->has($supervisorOf[$employee->id]))
            ->map(fn (Employee $root) => $node($root))
            ->values();

        return Inertia::render('hrm/org-chart', [
            'tree' => $tree,
            'unplaced' => $supervisorOf->filter(fn ($id) => $id === null)->count(),
        ]);
    }
}
