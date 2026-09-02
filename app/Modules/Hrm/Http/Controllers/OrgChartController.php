<?php

namespace App\Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hrm\Enums\EmployeeStatus;
use App\Modules\Hrm\Models\Employee;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The organization chart (Module 1, Slice 1): who reports to whom, as a tree.
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
        $employees = Employee::query()
            ->where('status', '!=', EmployeeStatus::Separated)
            ->whereHas('assignments')
            ->with('assignments.designation')
            ->orderBy('first_name')
            ->get();

        $byId = $employees->keyBy('id');

        $node = function (Employee $employee) use (&$node, $employees): array {
            return [
                'id' => $employee->id,
                'name' => $employee->fullName(),
                'code' => $employee->employee_code,
                'designation' => $employee->currentAssignment()?->designation?->name,
                'reports' => $employees
                    ->filter(fn (Employee $candidate) => $candidate->reports_to === $employee->id)
                    ->map(fn (Employee $report) => $node($report))
                    ->values()
                    ->all(),
            ];
        };

        // A root is anyone with no supervisor - or whose supervisor is not on
        // the chart (separated, or another business). An active person must
        // never vanish because the person above them left.
        $tree = $employees
            ->filter(fn (Employee $employee) => $employee->reports_to === null || ! $byId->has($employee->reports_to))
            ->map(fn (Employee $root) => $node($root))
            ->values();

        return Inertia::render('hrm/org-chart', [
            'tree' => $tree,
            'unplaced' => $this->unplacedCount($employees),
        ]);
    }

    /**
     * How many active people have no reporting line recorded yet - the number
     * that tells HR whether the chart is finished or still being collected.
     */
    private function unplacedCount(Collection $employees): int
    {
        return $employees->whereNull('reports_to')->count();
    }
}
