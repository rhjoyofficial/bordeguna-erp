<?php

namespace App\Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Models\Designation;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\CreateEmployee;
use App\Modules\Hrm\Actions\SeparateEmployee;
use App\Modules\Hrm\Enums\EmployeeStatus;
use App\Modules\Hrm\Enums\EmploymentType;
use App\Modules\Hrm\Enums\SeparationType;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeController extends Controller
{
    public function index(): Response
    {
        $this->authorize('hrm.employees.manage');

        // Employees are central; "who works here" is the set with a currently
        // active primary assignment in this business (the assignments relation
        // is business-scoped, so this is automatically the current tenant).
        $employees = Employee::whereHas('assignments', fn ($query) => $query->active()->where('is_primary', true))
            ->with(['assignments' => fn ($query) => $query->active()->where('is_primary', true)->with(['office:id,name', 'designation:id,name'])])
            ->orderBy('first_name')->orderBy('last_name')->get()
            ->map(function (Employee $employee) {
                $assignment = $employee->assignments->first();

                return [
                    'id' => $employee->id,
                    'employee_code' => $employee->employee_code,
                    'name' => $employee->fullName(),
                    'employment_type' => $employee->employment_type->label(),
                    'status' => $employee->status->value,
                    'office' => $assignment?->office?->name,
                    'designation' => $assignment?->designation?->name,
                ];
            });

        return Inertia::render('hrm/employees/index', [
            'employees' => $employees,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('hrm.employees.manage');

        return Inertia::render('hrm/employees/create', [
            'employment_types' => collect(EmploymentType::cases())->map(fn (EmploymentType $type) => [
                'value' => $type->value,
                'label' => $type->label(),
            ]),
            'offices' => Office::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'designations' => Designation::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request, CreateEmployee $action): RedirectResponse
    {
        $this->authorize('hrm.employees.manage');

        $businessId = app(BusinessContext::class)->id();

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'employment_type' => ['required', new Enum(EmploymentType::class)],
            'joining_date' => ['nullable', 'date'],
            'personal_email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'national_id' => ['nullable', 'string', 'max:100'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'office_id' => ['nullable', Rule::exists('offices', 'id')->where('business_id', $businessId)],
            'designation_id' => ['nullable', Rule::exists('designations', 'id')->where('business_id', $businessId)],
        ]);

        $employee = $action->handle(
            data: $data,
            assignment: [
                'office_id' => $data['office_id'] ?? null,
                'designation_id' => $data['designation_id'] ?? null,
            ],
        );

        return redirect()->route('hrm.employees.show', $employee)->with('success', "Employee {$employee->employee_code} created.");
    }

    public function show(Employee $employee): Response
    {
        $this->authorize('hrm.employees.manage');

        // Central model — confine to the current business: an employee is
        // visible here only if they hold (or held) an assignment in it.
        abort_unless($employee->assignments()->exists(), 404);

        $employee->load([
            'user:id,name,email',
            'assignments' => fn ($query) => $query->latest('effective_from')->with([
                'office:id,name', 'department:id,name', 'section:id,name', 'designation:id,name',
            ]),
        ]);

        return Inertia::render('hrm/employees/show', [
            'employee' => [
                'id' => $employee->id,
                'employee_code' => $employee->employee_code,
                'name' => $employee->fullName(),
                'employment_type' => $employee->employment_type->label(),
                'status' => $employee->status->value,
                'separation_type' => $employee->separation_type?->label(),
                'separation_date' => $employee->separation_date?->toDateString(),
                'separation_reason' => $employee->separation_reason,
                'personal_email' => $employee->personal_email,
                'phone' => $employee->phone,
                'national_id' => $employee->national_id,
                'date_of_birth' => $employee->date_of_birth?->toDateString(),
                'joining_date' => $employee->joining_date?->toDateString(),
                'user' => $employee->user ? ['name' => $employee->user->name, 'email' => $employee->user->email] : null,
                'assignments' => $employee->assignments->map(fn (EmployeeAssignment $assignment) => [
                    'id' => $assignment->id,
                    'is_primary' => $assignment->is_primary,
                    'office' => $assignment->office?->name,
                    'department' => $assignment->department?->name,
                    'section' => $assignment->section?->name,
                    'designation' => $assignment->designation?->name,
                    'effective_from' => $assignment->effective_from?->toDateString(),
                    'effective_to' => $assignment->effective_to?->toDateString(),
                ]),
            ],
            'transitionable_statuses' => collect($employee->transitionableStatuses())
                ->map(fn (EmployeeStatus $status) => ['value' => $status->value, 'label' => $status->label()]),
            'can_separate' => $employee->canSeparate(),
            'separation_types' => collect(SeparationType::cases())
                ->map(fn (SeparationType $type) => ['value' => $type->value, 'label' => $type->label()]),
        ]);
    }

    public function status(Request $request, Employee $employee): RedirectResponse
    {
        $this->authorize('hrm.employees.manage');
        abort_unless($employee->assignments()->exists(), 404);

        $allowed = collect($employee->transitionableStatuses())->map(fn (EmployeeStatus $status) => $status->value);

        $data = $request->validate([
            'status' => ['required', Rule::in($allowed)],
        ]);

        $employee->update(['status' => $data['status']]);

        return back()->with('success', 'Employee status updated.');
    }

    public function separate(Request $request, Employee $employee, SeparateEmployee $action): RedirectResponse
    {
        $this->authorize('hrm.employees.manage');
        abort_unless($employee->assignments()->exists(), 404);

        if (! $employee->canSeparate()) {
            return back()->with('error', 'This employee is already separated.');
        }

        $data = $request->validate([
            'separation_type' => ['required', new Enum(SeparationType::class)],
            'separation_date' => ['required', 'date'],
            'separation_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $action->handle(
            employee: $employee,
            type: SeparationType::from($data['separation_type']),
            date: $data['separation_date'],
            reason: $data['separation_reason'] ?? null,
        );

        return back()->with('success', "{$employee->fullName()} separated.");
    }
}
