<?php

namespace App\Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Dashboard\AttendanceTrend;
use App\Modules\Hrm\Dashboard\DashboardFilters;
use App\Modules\Hrm\Dashboard\DashboardRegistry;
use App\Modules\Hrm\Models\DailyAttendance;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Reporting\ReportScope;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The HRM dashboard (RPT-04).
 *
 * **It computes no KPI.** It resolves the filters, asks the registry which
 * widgets this viewer may see, and returns their values. Every figure is
 * produced by a widget, which is the separation Joy asked for and the thing the
 * existing ERP `DashboardController` does not have — six private query methods
 * and inlined permission checks, with no way to add a seventh tile except by
 * editing it.
 *
 * **Access is `ReportScope`, unchanged from W3** (C10d, extended to tiles by
 * C11d). A widget the viewer may not see is absent from the payload rather than
 * rendered empty, and a restricted manager's `department_id` is overwritten
 * before any widget runs.
 */
class HrmDashboardController extends Controller
{
    public function __construct(
        private readonly DashboardRegistry $widgets,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        // ⚠ Bug hunt 16 Oct 2026: the route had no gate, and only the tiles
        // went through the registry's permission filter. The chart, the
        // headcount per department and the payroll-holds count reached any
        // signed-in employee. Same rule as the menu that links here.
        abort_unless(
            $user->can('hrm.attendance.manage') || $user->can('hrm.salary.manage') || $user->can('hrm.salary.view'),
            403,
        );

        $validated = $request->validate([
            'office_id' => 'nullable|integer',
            'department_id' => 'nullable|integer',
            'range_from' => 'nullable|date',
            // A range that ends before it starts is a mistake that silently
            // returns nothing, which reads as "no data" rather than "bad input"
            // — the same rule ReportParameter applies.
            'range_to' => 'nullable|date|after_or_equal:range_from',
        ]);

        $scope = ReportScope::for($request->user());

        // A department manager whose own department is not set cannot be
        // narrowed to it, and the unscoped figures are the whole company:
        // withheld, as the reports withhold them (ReportScope fails closed).
        abort_unless($scope->resolvable(), 403, 'Your own department is not set, so this dashboard cannot be limited to it. Ask HR to set it.');

        $ownDepartment = $scope->restricted ? $scope->departmentId : null;
        $filters = DashboardFilters::make($validated, $scope);

        return Inertia::render('hrm/dashboard', [
            'widgets' => $this->widgets->resolveFor($request->user(), $filters),
            // ⚠ The chart, scoped by the SAME office and department clauses the
            // tiles use. A picture that disagrees with the numbers above it is
            // worse than no picture, because the picture is what gets believed.
            'trend' => AttendanceTrend::for($filters, app(BusinessContext::class)),
            // The DreamsERP-style hero line: what actually needs this person.
            // Real numbers only - the approvals count already rides in the
            // shared props, so this adds the one figure it lacks.
            'attention' => [
                'holds' => DailyAttendance::where('holds_payroll', true)
                    ->when($ownDepartment, fn ($query, $id) => $query->whereIn(
                        'employee_id',
                        EmployeeAssignment::query()->where('is_primary', true)->where('department_id', $id)->select('employee_id'),
                    ))
                    ->count(),
            ],
            // Who sits where, for the distribution panel: active primary
            // assignments per department. Real data, no invented departments.
            'distribution' => EmployeeAssignment::query()
                ->with('department:id,name')
                ->where('is_primary', true)
                ->active()
                // A department manager sees their own department only.
                ->when($ownDepartment, fn ($query, $id) => $query->where('department_id', $id))
                ->get()
                ->groupBy(fn (EmployeeAssignment $a) => $a->department?->name ?? 'Unassigned')
                ->map(fn ($group, $name) => ['department' => $name, 'count' => $group->count()])
                ->sortByDesc('count')
                ->values(),
            'filters' => [
                'office_id' => $filters->officeId(),
                'department_id' => $filters->departmentId(),
                'range_from' => $filters->from()->toDateString(),
                'range_to' => $filters->to()->toDateString(),
            ],
            'offices' => Office::orderBy('name')->get(['id', 'name'])
                ->map(fn (Office $o) => ['value' => $o->id, 'label' => $o->name])->all(),
            // A restricted manager is offered only their own department, and the
            // control is locked — the same treatment the report parameter screen
            // gives it. A dropdown whose other entries are overwritten on submit
            // reads as a choice and behaves as a lie.
            'departments' => $this->departments($scope),
            'department_locked' => $scope->restricted,
        ]);
    }

    /**
     * @return array<int, array{value: int, label: string}>
     */
    private function departments(ReportScope $scope): array
    {
        return Department::query()
            ->when($scope->restricted, fn ($q) => $q->whereKey($scope->departmentId))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Department $d) => ['value' => $d->id, 'label' => $d->name])
            ->all();
    }
}
