<?php

namespace App\Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Hrm\Actions\SaveSalaryStructure;
use App\Modules\Hrm\Enums\SalaryCalculationBasis;
use App\Modules\Hrm\Enums\SalaryComponentType;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\SalaryComponent;
use App\Modules\Hrm\Models\SalaryStructure;
use App\Modules\Hrm\Salary\ResolvedSalaryLine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum as EnumRule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Salary components and per-employee structures (SAL-01..06, C3, C7c).
 *
 * Access follows Correction C5 / ACL-02 exactly: `hrm.salary.manage` for
 * Payroll HR who set structures, `hrm.salary.view` for Finance and authorised
 * Management who only read them. Department Managers hold neither, and an
 * employee sees only their own figures through self-service.
 *
 * Nothing here calculates a payslip. This screen shows the contractual salary;
 * what a month actually pays is Phase 3b's business.
 */
class SalaryController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizeSalaryRead($request);

        $employees = Employee::whereHas('assignments', fn ($q) => $q->active()->where('is_primary', true))
            ->orderBy('first_name')->orderBy('last_name')->get();

        return Inertia::render('hrm/salary/index', [
            'can_manage' => (bool) $request->user()?->can('hrm.salary.manage'),
            'employees' => $employees->map(function (Employee $employee) {
                $structure = SalaryStructure::activeFor($employee->id);
                $breakdown = $structure?->breakdown();

                return [
                    'id' => $employee->id,
                    'employee_code' => $employee->employee_code,
                    'name' => $employee->fullName(),
                    'has_structure' => $structure !== null,
                    'effective_from' => $structure ? Carbon::parse($structure->effective_from)->toDateString() : null,
                    'basic' => $breakdown?->basic,
                    'gross' => $breakdown?->gross,
                ];
            }),
            'components' => SalaryComponent::inPayslipOrder()->get()->map(fn (SalaryComponent $c) => $this->presentComponent($c)),
            'types' => collect(SalaryComponentType::cases())
                ->map(fn (SalaryComponentType $t) => ['value' => $t->value, 'label' => $t->label()]),
            'bases' => collect(SalaryCalculationBasis::cases())
                ->map(fn (SalaryCalculationBasis $b) => [
                    'value' => $b->value,
                    'label' => $b->label(),
                    'allowed_for_earning' => $b->allowedForEarning(),
                    'allowed_for_deduction' => $b->allowedForDeduction(),
                ]),
        ]);
    }

    /**
     * One employee's current structure plus the full effective-dated history.
     */
    public function show(Request $request, Employee $employee): Response
    {
        $this->authorizeSalaryRead($request);
        abort_unless($employee->assignments()->exists(), 404);

        $history = SalaryStructure::historyFor($employee->id);
        $current = SalaryStructure::activeFor($employee->id);

        return Inertia::render('hrm/salary/show', [
            'can_manage' => (bool) $request->user()?->can('hrm.salary.manage'),
            'employee' => [
                'id' => $employee->id,
                'employee_code' => $employee->employee_code,
                'name' => $employee->fullName(),
                'office' => $employee->currentAssignment()?->office?->name,
                'designation' => $employee->currentAssignment()?->designation?->name,
            ],
            'current' => $current === null ? null : $this->presentStructure($current, $current->id),
            'history' => $history->map(fn (SalaryStructure $s) => $this->presentStructure($s, $current?->id)),
            'components' => SalaryComponent::active()->inPayslipOrder()->get()
                ->map(fn (SalaryComponent $c) => $this->presentComponent($c)),
        ]);
    }

    public function store(Request $request, Employee $employee, SaveSalaryStructure $action): RedirectResponse
    {
        $this->authorize('hrm.salary.manage');
        abort_unless($employee->assignments()->exists(), 404);

        $validated = $request->validate([
            'effective_from' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
            // ADJ-01 / Correction C8d — who may be assigned overtime at all.
            'overtime_eligible' => ['nullable', 'boolean'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.salary_component_id' => [
                'required', 'integer',
                Rule::exists('salary_components', 'id')->where('business_id', $this->businessId()),
            ],
            'lines.*.value' => ['required', 'numeric', 'min:0'],
        ]);

        $action->handle(
            $employee,
            $validated['effective_from'],
            $validated['lines'],
            $validated['reason'] ?? null,
            (bool) ($validated['overtime_eligible'] ?? false),
        );

        return back()->with('success', 'Salary structure saved.');
    }

    // --- Components -----------------------------------------------------------

    public function storeComponent(Request $request): RedirectResponse
    {
        $this->authorize('hrm.salary.manage');

        SalaryComponent::create($this->componentRules($request));

        return back()->with('success', 'Component created.');
    }

    public function updateComponent(Request $request, SalaryComponent $component): RedirectResponse
    {
        $this->authorize('hrm.salary.manage');

        $component->update($this->componentRules($request, $component));

        return back()->with('success', 'Component updated.');
    }

    public function toggleComponent(SalaryComponent $component): RedirectResponse
    {
        $this->authorize('hrm.salary.manage');

        // The Basic component underpins every percentage calculation, so it is
        // not something to switch off by accident.
        if ($component->is_basic && $component->is_active) {
            return back()->with('error', 'Basic cannot be deactivated — every other component is measured against it.');
        }

        $component->update(['is_active' => ! $component->is_active]);

        return back()->with('success', $component->is_active ? 'Component activated.' : 'Component deactivated.');
    }

    /**
     * @return array<string, mixed>
     */
    private function componentRules(Request $request, ?SalaryComponent $component = null): array
    {
        $validated = $request->validate([
            'code' => [
                'required', 'string', 'max:50', 'regex:/^[a-z0-9_]+$/',
                Rule::unique('salary_components', 'code')->where('business_id', $this->businessId())->ignore($component?->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', new EnumRule(SalaryComponentType::class)],
            'calculation_basis' => ['required', new EnumRule(SalaryCalculationBasis::class)],
            'sequence' => ['required', 'integer', 'min:0', 'max:9999'],
            'description' => ['nullable', 'string', 'max:255'],
        ], [
            'code.regex' => 'Use lowercase letters, numbers and underscores only.',
        ]);

        $type = SalaryComponentType::from($validated['type']);
        $basis = SalaryCalculationBasis::from($validated['calculation_basis']);

        if ($type === SalaryComponentType::Earning && ! $basis->allowedForEarning()) {
            throw ValidationException::withMessages([
                'calculation_basis' => 'An earning cannot be a percentage of Gross — Gross is the sum of the earnings.',
            ]);
        }

        if ($type === SalaryComponentType::Deduction && ! $basis->allowedForDeduction()) {
            throw ValidationException::withMessages([
                'calculation_basis' => 'A deduction cannot be charged per day reported for work. That basis is for allowances earned by attending (C14).',
            ]);
        }

        // `is_basic` is deliberately absent: exactly one Basic exists per
        // business, seeded from SAL-02, and it is not something a new component
        // may claim.
        return $validated;
    }

    private function authorizeSalaryRead(Request $request): void
    {
        abort_unless(
            $request->user()?->can('hrm.salary.manage') || $request->user()?->can('hrm.salary.view'),
            403,
        );
    }

    private function businessId(): int
    {
        return app(\App\Modules\Foundation\Support\BusinessContext::class)->id();
    }

    /**
     * @return array<string, mixed>
     */
    private function presentComponent(SalaryComponent $component): array
    {
        return [
            'id' => $component->id,
            'code' => $component->code,
            'name' => $component->name,
            'type' => $component->type->value,
            'type_label' => $component->type->label(),
            'is_basic' => $component->is_basic,
            'calculation_basis' => $component->calculation_basis->value,
            'basis_label' => $component->calculation_basis->label(),
            'is_percentage' => $component->calculation_basis->isPercentage(),
            // C14: the figure HR types for one of these is a *daily rate*, not a
            // monthly amount, and the form must say so. Without it the field
            // looks identical to a fixed monthly component and 246 could
            // reasonably be read as a monthly allowance.
            'is_priced_by_attendance' => $component->calculation_basis->isPricedByAttendance(),
            'sequence' => $component->sequence,
            'is_statutory' => $component->is_statutory,
            'description' => $component->description,
            'is_active' => $component->is_active,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentStructure(SalaryStructure $structure, ?int $currentId): array
    {
        $breakdown = $structure->breakdown();
        $from = Carbon::parse($structure->effective_from);

        return [
            'id' => $structure->id,
            'effective_from' => $from->toDateString(),
            'effective_to' => $structure->effective_to ? Carbon::parse($structure->effective_to)->toDateString() : null,
            // "Current" means in force today, not merely open-ended. SAL-05
            // allows a revision to be dated ahead, and such a row has no end
            // date while not yet applying to anyone — treating the two as the
            // same thing made a scheduled increment invisible.
            'is_current' => $currentId !== null && $structure->id === $currentId,
            'is_future' => $from->gt(Carbon::today()),
            'overtime_eligible' => (bool) $structure->overtime_eligible,
            'reason' => $structure->reason,
            'created_by' => $structure->creator?->name,
            'basic' => $breakdown->basic,
            'gross' => $breakdown->gross,
            'contractual_deductions' => $breakdown->contractualDeductions,
            'contractual_net' => $breakdown->contractualNet(),
            'lines' => collect($breakdown->lines)->map(fn (ResolvedSalaryLine $line) => [
                'component_id' => $line->componentId,
                'name' => $line->name,
                'type' => $line->type->value,
                'is_basic' => $line->isBasic,
                'is_statutory' => $line->isStatutory,
                'value' => $line->value,
                'amount' => $line->amount,
                'formula' => $line->formula,
            ]),
        ];
    }
}
