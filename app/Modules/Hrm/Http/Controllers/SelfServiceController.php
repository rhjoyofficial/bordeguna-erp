<?php

namespace App\Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Actions\SubmitChangeRequest;
use App\Modules\Foundation\Models\Attachment;
use App\Modules\Foundation\Models\ChangeRequest;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Enums\EmployeeDocumentType;
use App\Modules\Hrm\Enums\MfsProvider;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\SalaryStructure;
use App\Modules\Hrm\Support\EmployeeChangeRequestPolicy;
use App\Modules\Hrm\Support\EmployeePayAdjustments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Employee Self-Service (ESS). Shows a signed-in user their OWN employee
 * record — no hrm.employees.manage permission required, because access is by
 * self-scope (P3), the rule that says "it's mine", not the HR grant the admin
 * screens use. Deliberately sparse and read-only (progressive disclosure,
 * plan §0.5.4): the few things an employee needs to see about themselves, not
 * the full HR admin surface. Attendance / leave / payslip self-views plug in
 * here as Phases 2-3 land, each reusing the same SelfScope gate.
 */
class SelfServiceController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        $employee = Employee::forUser($request->user());

        if ($employee === null) {
            return redirect()->route('dashboard')
                ->with('error', 'Your account is not linked to an employee record.');
        }

        $employee->load(['attachments' => fn ($query) => $query->latest('id')]);

        // Current placement in the business the user is working in (the
        // assignments relation is business-scoped, so this is null when their
        // active placement is in a different business — that's fine, the core
        // profile still shows).
        $assignment = $employee->currentAssignment();
        $assignment?->load([
            'office:id,name', 'department:id,name', 'section:id,name', 'designation:id,name',
            'supervisor:id,first_name,last_name,employee_code',
        ]);

        // Correction C5 / ACL-02 lists the employee themselves among the four
        // parties who may see salary — their own only. Read-only: changing pay
        // is a Payroll workflow, never self-service.
        $structure = SalaryStructure::activeFor($employee->id);
        $breakdown = $structure?->breakdown();

        return Inertia::render('hrm/my-profile/show', [
            // ⭐ My deductions (27 Oct 2026): the same list as My payslips, next
            // to his salary.
            'upcoming_adjustments' => EmployeePayAdjustments::upcoming($employee),
            'salary' => $breakdown === null ? null : [
                'effective_from' => Carbon::parse($structure->effective_from)->toDateString(),
                'basic' => $breakdown->basic,
                'gross' => $breakdown->gross,
                'lines' => collect($breakdown->lines)->map(fn ($line) => [
                    'name' => $line->name,
                    'type' => $line->type->value,
                    'amount' => $line->amount,
                    'formula' => $line->formula,
                ]),
            ],
            'employee' => [
                'employee_code' => $employee->employee_code,
                'name' => $employee->fullName(),
                'employment_type' => $employee->employment_type->label(),
                'status' => $employee->status->value,
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
                'placement' => $assignment ? [
                    'office' => $assignment->office?->name,
                    'department' => $assignment->department?->name,
                    'section' => $assignment->section?->name,
                    'designation' => $assignment->designation?->name,
                    'supervisor' => $assignment->supervisor
                        ? $assignment->supervisor->fullName().' ('.$assignment->supervisor->employee_code.')'
                        : null,
                    'since' => $assignment->effective_from?->toDateString(),
                ] : null,
                // Read-only: the same files HR manages, downloadable by the
                // employee themselves through attachments.show (which enforces
                // canAccessAttachments → SelfScope, so the self branch applies).
                'documents' => $employee->attachments->map(fn (Attachment $document) => [
                    'id' => $document->id,
                    'category' => EmployeeDocumentType::tryFrom($document->collection ?? '')?->label() ?? 'Other',
                    'original_name' => $document->original_name,
                    'size' => $document->size,
                    'expires_at' => $document->expires_at?->toDateString(),
                    'is_expired' => $document->isExpired(),
                    'url' => $document->url(),
                ]),
            ],
            // ESS is read-only: to change master data the employee submits an
            // approval-gated request. These describe the requestable groups (with
            // current values) and the employee's own past/pending requests.
            'requestable' => collect(EmployeeChangeRequestPolicy::descriptors())->map(fn (array $group) => [
                'key' => $group['key'],
                'label' => $group['label'],
                'fields' => collect($group['fields'])->map(fn (array $field) => [
                    'name' => $field['name'],
                    'label' => $field['label'],
                    'value' => $this->rawValue($employee, $field['name']),
                ]),
            ]),
            'mfs_providers' => collect(MfsProvider::cases())
                ->map(fn (MfsProvider $provider) => ['value' => $provider->value, 'label' => $provider->label()]),
            'change_requests' => $this->changeRequestsFor($employee),
        ]);
    }

    public function requestChange(Request $request, SubmitChangeRequest $submit): RedirectResponse
    {
        $employee = Employee::forUser($request->user());

        if ($employee === null) {
            return redirect()->route('dashboard')
                ->with('error', 'Your account is not linked to an employee record.');
        }

        $group = $request->validate(['group' => ['required', Rule::in(EmployeeChangeRequestPolicy::groupKeys())]])['group'];
        $data = $request->validate(EmployeeChangeRequestPolicy::rulesFor($group));

        // Only the fields that actually differ from the current record become
        // the proposed change set.
        $changes = [];
        foreach ($data as $field => $value) {
            $new = ($value === '' || $value === null) ? null : (string) $value;
            if ($this->rawValue($employee, $field) !== $new) {
                $changes[$field] = $new;
            }
        }

        if ($changes === []) {
            return back()->with('error', 'Nothing changed — no request submitted.');
        }

        $submit->handle(
            subject: $employee,
            changes: $changes,
            workflowKey: EmployeeChangeRequestPolicy::workflowFor($group),
            requestedBy: $request->user(),
            businessId: app(BusinessContext::class)->id(),
        );

        return back()->with('success', 'Update request submitted for approval.');
    }

    /**
     * The employee's own change requests in the current business, newest first.
     */
    private function changeRequestsFor(Employee $employee): Collection
    {
        return ChangeRequest::where('subject_type', $employee->getMorphClass())
            ->where('subject_id', $employee->id)
            ->with(['approvalRequest.workflow.stages', 'subject'])
            ->latest()
            ->get()
            ->map(function (ChangeRequest $changeRequest) {
                $approval = $changeRequest->approvalRequest;
                $pendingMultiStage = $approval?->isPending() && $approval->totalStages() > 1;

                return [
                    'id' => $changeRequest->id,
                    'summary' => collect($changeRequest->describedChanges())
                        ->map(fn ($value, $label) => "{$label}: {$value}")->implode(', '),
                    'status' => $approval?->status->value ?? 'pending',
                    'stage' => $pendingMultiStage ? $approval->currentStage()?->name : null,
                    'requested_at' => $changeRequest->created_at->diffForHumans(),
                ];
            });
    }

    private function rawValue(Employee $employee, string $field): ?string
    {
        $value = $employee->{$field};

        return match (true) {
            $value === null => null,
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
            default => (string) $value,
        };
    }
}
