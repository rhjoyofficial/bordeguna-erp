<?php

namespace App\Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Actions\DeleteAttachment;
use App\Modules\Foundation\Actions\StoreAttachment;
use App\Modules\Foundation\Models\Attachment;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Designation;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Models\Section;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\CreateEmployee;
use App\Modules\Hrm\Actions\SeparateEmployee;
use App\Modules\Hrm\Actions\TransferEmployee;
use App\Modules\Hrm\Actions\UpdateEmployee;
use App\Modules\Hrm\Enums\EmployeeDocumentType;
use App\Modules\Hrm\Enums\EmployeeStatus;
use App\Modules\Hrm\Enums\EmploymentType;
use App\Modules\Hrm\Enums\MfsProvider;
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
                'supervisor:id,first_name,last_name,employee_code',
            ]),
            'attachments' => fn ($query) => $query->latest('id')->with('uploadedBy:id,name'),
        ]);

        $businessId = app(BusinessContext::class)->id();

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
                'present_address' => $employee->present_address,
                'permanent_address' => $employee->permanent_address,
                'national_id' => $employee->national_id,
                'birth_certificate_no' => $employee->birth_certificate_no,
                'date_of_birth' => $employee->date_of_birth?->toDateString(),
                'joining_date' => $employee->joining_date?->toDateString(),
                'confirmation_date' => $employee->confirmation_date?->toDateString(),
                'contract_end_date' => $employee->contract_end_date?->toDateString(),
                'emergency_contact_name' => $employee->emergency_contact_name,
                'emergency_contact_phone' => $employee->emergency_contact_phone,
                'emergency_contact_relationship' => $employee->emergency_contact_relationship,
                'bank_account_name' => $employee->bank_account_name,
                'bank_account_number' => $employee->bank_account_number,
                'bank_name' => $employee->bank_name,
                'bank_branch' => $employee->bank_branch,
                'bank_routing_number' => $employee->bank_routing_number,
                'mfs_provider' => $employee->mfs_provider?->label(),
                'mfs_account_number' => $employee->mfs_account_number,
                'user' => $employee->user ? ['name' => $employee->user->name, 'email' => $employee->user->email] : null,
                'assignments' => $employee->assignments->map(fn (EmployeeAssignment $assignment) => [
                    'id' => $assignment->id,
                    'is_primary' => $assignment->is_primary,
                    'office' => $assignment->office?->name,
                    'department' => $assignment->department?->name,
                    'section' => $assignment->section?->name,
                    'designation' => $assignment->designation?->name,
                    'supervisor' => $assignment->supervisor
                        ? $assignment->supervisor->fullName().' ('.$assignment->supervisor->employee_code.')'
                        : null,
                    'effective_from' => $assignment->effective_from?->toDateString(),
                    'effective_to' => $assignment->effective_to?->toDateString(),
                ]),
                // Documents (EMP-04) over the Phase 0 Attachment Engine. Viewing
                // the files themselves goes through attachments.show, which
                // enforces the Employee's canAccessAttachments gate on top of
                // the business scope.
                'documents' => $employee->attachments->map(fn (Attachment $document) => [
                    'id' => $document->id,
                    'category' => EmployeeDocumentType::tryFrom($document->collection ?? '')?->label() ?? 'Other',
                    'original_name' => $document->original_name,
                    'size' => $document->size,
                    'expires_at' => $document->expires_at?->toDateString(),
                    'is_expired' => $document->isExpired(),
                    'uploaded_by' => $document->uploadedBy?->name,
                    'url' => $document->url(),
                ]),
            ],
            'transitionable_statuses' => collect($employee->transitionableStatuses())
                ->map(fn (EmployeeStatus $status) => ['value' => $status->value, 'label' => $status->label()]),
            'can_separate' => $employee->canSeparate(),
            'separation_types' => collect(SeparationType::cases())
                ->map(fn (SeparationType $type) => ['value' => $type->value, 'label' => $type->label()]),
            // Reassignment form data: the org tree, job titles, and colleagues
            // who could be a supervisor. Only meaningful while still employed.
            'can_transfer' => $employee->status !== EmployeeStatus::Separated,
            'offices_tree' => Office::where('is_active', true)
                ->with([
                    'departments' => fn ($query) => $query->where('is_active', true)->orderBy('name')
                        ->with(['sections' => fn ($q) => $q->where('is_active', true)->orderBy('name')]),
                ])->orderBy('name')->get()
                ->map(fn (Office $office) => [
                    'id' => $office->id,
                    'name' => $office->name,
                    'departments' => $office->departments->map(fn (Department $department) => [
                        'id' => $department->id,
                        'name' => $department->name,
                        'sections' => $department->sections->map(fn (Section $section) => ['id' => $section->id, 'name' => $section->name]),
                    ]),
                ]),
            'designations' => Designation::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'supervisors' => Employee::whereHas('assignments', fn ($query) => $query->active()->where('is_primary', true))
                ->where('id', '!=', $employee->id)->orderBy('first_name')->orderBy('last_name')->get()
                ->map(fn (Employee $candidate) => ['id' => $candidate->id, 'name' => $candidate->fullName().' ('.$candidate->employee_code.')']),
            'document_types' => collect(EmployeeDocumentType::cases())
                ->map(fn (EmployeeDocumentType $type) => ['value' => $type->value, 'label' => $type->label()]),
        ]);
    }

    public function edit(Employee $employee): Response
    {
        $this->authorize('hrm.employees.manage');
        abort_unless($employee->assignments()->exists(), 404);

        return Inertia::render('hrm/employees/edit', [
            'employee' => array_merge(
                ['id' => $employee->id, 'employee_code' => $employee->employee_code, 'name' => $employee->fullName()],
                collect(Employee::EDITABLE_FIELDS)->mapWithKeys(fn (string $field) => [
                    $field => $this->fieldValue($employee, $field),
                ])->all(),
            ),
            'employment_types' => collect(EmploymentType::cases())
                ->map(fn (EmploymentType $type) => ['value' => $type->value, 'label' => $type->label()]),
            'mfs_providers' => collect(MfsProvider::cases())
                ->map(fn (MfsProvider $provider) => ['value' => $provider->value, 'label' => $provider->label()]),
        ]);
    }

    public function update(Request $request, Employee $employee, UpdateEmployee $action): RedirectResponse
    {
        $this->authorize('hrm.employees.manage');
        abort_unless($employee->assignments()->exists(), 404);

        $data = $request->validate($this->masterFieldRules());

        $action->handle($employee, $data);

        return redirect()->route('hrm.employees.show', $employee)->with('success', 'Employee details updated.');
    }

    /**
     * Validation for the EMP-04 master fields. Extracted so the future generic
     * Change Request flow can validate a self-service Employee Update Request
     * against the exact same rules the HR edit form uses.
     *
     * @return array<string, mixed>
     */
    private function masterFieldRules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'employment_type' => ['required', new Enum(EmploymentType::class)],
            'personal_email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'present_address' => ['nullable', 'string', 'max:1000'],
            'permanent_address' => ['nullable', 'string', 'max:1000'],
            'national_id' => ['nullable', 'string', 'max:100'],
            'birth_certificate_no' => ['nullable', 'string', 'max:100'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'joining_date' => ['nullable', 'date'],
            'confirmation_date' => ['nullable', 'date'],
            'contract_end_date' => ['nullable', 'date'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:50'],
            'emergency_contact_relationship' => ['nullable', 'string', 'max:100'],
            'bank_account_name' => ['nullable', 'string', 'max:255'],
            'bank_account_number' => ['nullable', 'string', 'max:100'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_branch' => ['nullable', 'string', 'max:255'],
            'bank_routing_number' => ['nullable', 'string', 'max:100'],
            'mfs_provider' => ['nullable', new Enum(MfsProvider::class)],
            'mfs_account_number' => ['nullable', 'string', 'max:50'],
        ];
    }

    /**
     * Serialise one editable field for the edit form: enums as their string
     * value, dates as Y-m-d, everything else as-is.
     */
    private function fieldValue(Employee $employee, string $field): mixed
    {
        $value = $employee->{$field};

        return match (true) {
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
            default => $value,
        };
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

    public function transfer(Request $request, Employee $employee, TransferEmployee $action): RedirectResponse
    {
        $this->authorize('hrm.employees.manage');
        abort_unless($employee->assignments()->exists(), 404);

        if ($employee->status === EmployeeStatus::Separated) {
            return back()->with('error', 'A separated employee cannot be reassigned.');
        }

        $current = $employee->currentAssignment();
        if (! $current) {
            return back()->with('error', 'This employee has no active assignment to reassign.');
        }

        $businessId = app(BusinessContext::class)->id();
        $eligibleSupervisors = Employee::whereHas('assignments', fn ($query) => $query->active()->where('is_primary', true))
            ->where('id', '!=', $employee->id)->pluck('id')->all();

        $data = $request->validate([
            'effective_from' => ['required', 'date', 'after_or_equal:'.$current->effective_from->toDateString()],
            'office_id' => ['nullable', Rule::exists('offices', 'id')->where('business_id', $businessId)],
            'department_id' => ['nullable', Rule::exists('departments', 'id')->where('office_id', $request->integer('office_id'))],
            'section_id' => ['nullable', Rule::exists('sections', 'id')->where('department_id', $request->integer('department_id'))],
            'designation_id' => ['nullable', Rule::exists('designations', 'id')->where('business_id', $businessId)],
            'supervisor_id' => ['nullable', Rule::in($eligibleSupervisors)],
        ]);

        $action->handle($employee, $current, $data['effective_from'], [
            'office_id' => $data['office_id'] ?? null,
            'department_id' => $data['department_id'] ?? null,
            'section_id' => $data['section_id'] ?? null,
            'designation_id' => $data['designation_id'] ?? null,
            'supervisor_id' => $data['supervisor_id'] ?? null,
        ]);

        return back()->with('success', "{$employee->fullName()} reassigned.");
    }

    public function uploadDocument(Request $request, Employee $employee, StoreAttachment $action): RedirectResponse
    {
        $this->authorize('hrm.employees.manage');
        abort_unless($employee->assignments()->exists(), 404);

        $data = $request->validate([
            'category' => ['required', new Enum(EmployeeDocumentType::class)],
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $action->handle(
            attachable: $employee,
            file: $request->file('file'),
            collection: $data['category'],
            uploadedBy: $request->user(),
            expiresAt: $data['expires_at'] ?? null,
        );

        return back()->with('success', 'Document uploaded.');
    }

    public function destroyDocument(Employee $employee, Attachment $attachment, DeleteAttachment $action): RedirectResponse
    {
        $this->authorize('hrm.employees.manage');
        abort_unless($employee->assignments()->exists(), 404);

        // The file must belong to this employee — never let one employee's URL
        // delete another's document (or a logo).
        abort_unless(
            $attachment->attachable_type === $employee->getMorphClass()
                && $attachment->attachable_id === $employee->id,
            404,
        );

        $action->handle($attachment);

        return back()->with('success', 'Document removed.');
    }
}
