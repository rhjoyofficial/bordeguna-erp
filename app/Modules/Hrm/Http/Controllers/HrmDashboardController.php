<?php

namespace App\Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Dashboard\AttendanceTrend;
use App\Modules\Hrm\Dashboard\DashboardFilters;
use App\Modules\Hrm\Dashboard\DashboardRegistry;
use App\Modules\Hrm\Dashboard\PayrollOutlook;
use App\Modules\Hrm\Enums\EmployeeStatus;
use App\Modules\Hrm\Enums\EmploymentType;
use App\Modules\Hrm\Models\DailyAttendance;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Reporting\ReportScope;
use App\Modules\Hrm\Support\PayrollCycle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
        $filters = DashboardFilters::make($validated, $scope);
        $widgets = $this->widgets->resolveFor($request->user(), $filters);

        return Inertia::render('hrm/dashboard', [
            'widgets' => $widgets,
            // ⚠ The chart, scoped by the SAME office and department clauses the
            // tiles use. A picture that disagrees with the numbers above it is
            // worse than no picture, because the picture is what gets believed.
            'trend' => AttendanceTrend::for($filters, app(BusinessContext::class)),
            // The DreamsERP-style hero line: what actually needs this person.
            // Real numbers only - the approvals count already rides in the
            // shared props, so this adds the one figure it lacks.
            'attention' => [
                'holds' => DailyAttendance::where('holds_payroll', true)->count(),
            ],
            // Who sits where, for the distribution panel: active primary
            // assignments per department. Real data, no invented departments.
            'distribution' => EmployeeAssignment::query()
                ->with('department:id,name')
                ->where('is_primary', true)
                ->active()
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
                // The single day the "Today" strip is about: the range end,
                // clamped to today (DashboardFilters::asAt). Sent so the page
                // can name the day and offer to process exactly it.
                'as_at' => $filters->asAt()->toDateString(),
            ],
            // The range chips (Today / This week / This pay cycle). Resolved
            // here, not in the browser: the pay cycle is a business setting
            // (PayrollCycle, 24th to 23rd for Bor de Guna) the page cannot know.
            'ranges' => $this->ranges(),
            // The payroll card, ONLY for a viewer the registry already let see
            // the payroll status tile. Same gate, by construction: a restricted
            // manager gets null, not a smaller card (ACL-02 / C11d).
            'payroll' => in_array('payroll_current_status', array_column($widgets, 'key'), true)
                ? PayrollOutlook::for($filters)
                : null,
            // Headcount movements for the "Staff by team" card, gated like the
            // employee register itself. Null, not zeros, for anyone else.
            'staff' => $request->user()->can('hrm.employees.manage') ? $this->staffCounts() : null,
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
     * The three preset ranges, as the dates the filters would carry.
     *
     * ⚠ "This week" is Saturday to Friday. Bor de Guna's week starts on
     * Saturday and Friday is the weekly off, so a Monday-start week would put
     * the first two worked days of every week into the previous one.
     *
     * @return array<string, array{from: string, to: string, label?: string}>
     */
    private function ranges(): array
    {
        $today = now()->startOfDay();
        $cycle = PayrollCycle::containing($today);

        return [
            'today' => ['from' => $today->toDateString(), 'to' => $today->toDateString()],
            'week' => [
                'from' => $today->copy()->startOfWeek(Carbon::SATURDAY)->toDateString(),
                'to' => $today->copy()->endOfWeek(Carbon::FRIDAY)->toDateString(),
            ],
            'cycle' => [
                'from' => $cycle['start']->toDateString(),
                'to' => $cycle['end']->toDateString(),
                'label' => Carbon::create($cycle['year'], $cycle['month'], 1)->format('F Y'),
            ],
        ];
    }

    /**
     * Headcount movements from the employee register: three counts, no lists.
     *
     * "In this business" means a primary assignment here, which is how the
     * employee register itself decides who belongs (EmployeeController); the
     * assignment table carries the business scope, the employee table does not.
     *
     * @return array{on_probation: int, joined_this_year: int, left_this_year: int}
     */
    private function staffCounts(): array
    {
        $year = now()->year;
        $here = fn (Builder $q) => $q->where('is_primary', true);

        return [
            // The register's own definition of "on probation": the probation
            // employment type, not separated, with an assignment still running.
            'on_probation' => Employee::query()
                ->where('employment_type', EmploymentType::Probation)
                ->where('status', '!=', EmployeeStatus::Separated)
                ->whereHas('assignments', fn (Builder $q) => $q->active()->where('is_primary', true))
                ->count(),
            'joined_this_year' => Employee::query()
                ->whereHas('assignments', $here)
                ->whereYear('joining_date', $year)
                ->count(),
            'left_this_year' => Employee::query()
                ->whereHas('assignments', $here)
                ->where('status', EmployeeStatus::Separated)
                ->whereYear('separation_date', $year)
                ->count(),
        ];
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
