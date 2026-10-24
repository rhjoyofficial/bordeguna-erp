<?php

namespace App\Modules\Hrm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Foundation\Actions\DeleteAttachment;
use App\Modules\Foundation\Actions\SendPasswordSetupLink;
use App\Modules\Foundation\Actions\StoreAttachment;
use App\Modules\Foundation\Corrections\ValidatesActionReason;
use App\Modules\Foundation\Models\Attachment;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Designation;
use App\Modules\Foundation\Models\Office;
use App\Modules\Foundation\Models\Section;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Hrm\Actions\ConfirmEmployee;
use App\Modules\Hrm\Actions\CreateEmployee;
use App\Modules\Hrm\Actions\CreateEmployeeLogin;
use App\Modules\Hrm\Actions\DeleteDraftEmployee;
use App\Modules\Hrm\Actions\RemoveEmployeeDocument;
use App\Modules\Hrm\Actions\SeparateEmployee;
use App\Modules\Hrm\Actions\TransferEmployee;
use App\Modules\Hrm\Actions\UndoSeparation;
use App\Modules\Hrm\Actions\UpdateEmployee;
use App\Modules\Hrm\Corrections\EmployeeActionPolicy;
use App\Modules\Hrm\Corrections\HrReasons;
use App\Modules\Hrm\Enums\EmployeeDocumentType;
use App\Modules\Hrm\Enums\EmployeeStatus;
use App\Modules\Hrm\Enums\EmploymentType;
use App\Modules\Hrm\Enums\MfsProvider;
use App\Modules\Hrm\Enums\SalaryPaymentMethod;
use App\Modules\Hrm\Enums\SeparationType;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\EmployeeAssignment;
use App\Modules\Hrm\Support\DateOfBirthRule;
use App\Support\RecordTimeline;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class EmployeeController extends Controller
{
    use ValidatesActionReason;

    public function index(Request $request): Response
    {
        $this->authorize('hrm.employees.manage');

        // ⭐ 27 Sep 2026 (Joy): separated staff stay in the ERP for good and must
        // be viewable in future. The default list is who works here now; the
        // "former" scope lists people who have left, reachable from a tab so
        // nobody has to know a URL.
        $scope = $request->string('scope')->toString() === 'former' ? 'former' : 'current';

        $formerCount = Employee::where('status', EmployeeStatus::Separated)
            ->whereHas('assignments', fn ($query) => $query->where('is_primary', true))
            ->count();

        // Employees are central; "who works here" is the set with a currently
        // active primary assignment in this business (the assignments relation
        // is business-scoped, so this is automatically the current tenant).
        // ⚠ EMPLOYEE CODE ORDER, EVERYWHERE (Joy, 24 Aug 2026: "employee lists
        // must order by employee_code, nothing else"). Every listing and picker
        // in the application follows this, so the same person sits in the same
        // place on every screen and a name spelled two ways cannot move them.
        // Probation tracking (Module 5, Slice 3): probations that RAN OUT with
        // nobody confirmed. Computed fresh every load - no flag to go stale.
        // The rule and the click (ConfirmEmployee) have existed since August;
        // this is only the surface that makes them happen.
        $probationOverdue = Employee::query()
            ->where('status', 'active')
            ->where('employment_type', 'probation')
            ->whereNull('confirmation_date')
            ->whereNotNull('probation_months')
            ->whereHas('assignments', fn ($query) => $query->active()->where('is_primary', true))
            ->orderBy('employee_code')
            ->get()
            ->filter(fn (Employee $employee) => $employee->joining_date !== null
                && $employee->joining_date->copy()->addMonthsNoOverflow((int) $employee->probation_months)->lte(now()))
            ->map(fn (Employee $employee) => [
                'id' => $employee->id,
                'employee_code' => $employee->employee_code,
                'name' => $employee->fullName(),
                'joining_date' => $employee->joining_date->toDateString(),
                'probation_months' => $employee->probation_months,
                'probation_ended_on' => $employee->joining_date->copy()
                    ->addMonthsNoOverflow((int) $employee->probation_months)->toDateString(),
            ])
            ->values();

        if ($scope === 'former') {
            $former = Employee::where('status', EmployeeStatus::Separated)
                ->whereHas('assignments', fn ($query) => $query->where('is_primary', true))
                ->with([
                    'assignments' => fn ($query) => $query->where('is_primary', true)
                        ->orderByDesc('effective_from')->with(['office:id,name', 'designation:id,name']),
                    'attachments' => fn ($query) => $query->where('collection', 'photo'),
                ])
                ->orderBy('employee_code')->get()
                ->map(function (Employee $employee) {
                    $assignment = $employee->assignments->first();

                    return [
                        'id' => $employee->id,
                        'employee_code' => $employee->employee_code,
                        'name' => $employee->fullName(),
                        'photo_url' => $employee->attachments->first()?->url(),
                        'employment_type' => $employee->employment_type->label(),
                        'status' => $employee->status->value,
                        'office' => $assignment?->office?->name,
                        'designation' => $assignment?->designation?->name,
                        'separation_type' => $employee->separation_type?->label(),
                        'separation_date' => $employee->separation_date?->toDateString(),
                    ];
                });

            return Inertia::render('hrm/employees/index', [
                'employees' => $former,
                'probationOverdue' => [],
                'scope' => 'former',
                'formerCount' => $formerCount,
            ]);
        }

        $employees = Employee::whereHas('assignments', fn ($query) => $query->active()->where('is_primary', true))
            ->with([
                'assignments' => fn ($query) => $query->active()->where('is_primary', true)->with(['office:id,name', 'designation:id,name']),
                'attachments' => fn ($query) => $query->where('collection', 'photo'),
            ])
            ->orderBy('employee_code')->get()
            ->map(function (Employee $employee) {
                $assignment = $employee->assignments->first();

                return [
                    'id' => $employee->id,
                    'employee_code' => $employee->employee_code,
                    'name' => $employee->fullName(),
                    'photo_url' => $employee->attachments->first()?->url(),
                    'employment_type' => $employee->employment_type->label(),
                    'may_clock_in_remotely' => (bool) $employee->may_clock_in_remotely,
                    'remote_clock_in_granted_by' => $employee->remoteClockInGrantedBy?->name,
                    'remote_clock_in_granted_at' => $employee->remote_clock_in_granted_at?->toDayDateTimeString(),
                    'remote_clock_in_reason' => $employee->remote_clock_in_reason,
                    'status' => $employee->status->value,
                    'office' => $assignment?->office?->name,
                    'designation' => $assignment?->designation?->name,
                ];
            });

        return Inertia::render('hrm/employees/index', [
            'employees' => $employees,
            'probationOverdue' => $probationOverdue,
            'scope' => 'current',
            'formerCount' => $formerCount,
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
            'date_of_birth' => DateOfBirthRule::rules(),
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

    public function show(Request $request, Employee $employee): Response
    {
        $this->authorize('hrm.employees.manage');

        // Central model — confine to the current business: an employee is
        // visible here only if they hold (or held) an assignment in it.
        abort_unless($employee->assignments()->exists(), 404);

        $employee->load([
            // is_active is read by the login card: a deactivated account is
            // a link that leads nowhere, and the card has to say so.
            'user:id,name,email,is_active',
            // Read by the remote clock-in card, which must name who granted it.
            'remoteClockInGrantedBy:id,name',
            'assignments' => fn ($query) => $query->latest('effective_from')->with([
                'office:id,name', 'department:id,name', 'section:id,name', 'designation:id,name',
                'supervisor:id,first_name,last_name,employee_code',
            ]),
            'attachments' => fn ($query) => $query->latest('id')->with('uploadedBy:id,name'),
        ]);

        $businessId = app(BusinessContext::class)->id();

        $mailer = app(SendPasswordSetupLink::class);
        $linkLifetime = $mailer->lifetimeMinutes();

        $passwordLinkSentAt = $employee->user === null ? null : DB::table('password_reset_tokens')
            ->where('email', $employee->user->email)->value('created_at');
        $passwordLinkSentAt = $passwordLinkSentAt === null ? null : Carbon::parse($passwordLinkSentAt);

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
                'salary_payment_method' => $employee->salary_payment_method?->label(),
                'user' => $employee->user ? [
                    'id' => $employee->user->id,
                    'name' => $employee->user->name,
                    'email' => $employee->user->email,
                    'is_active' => $employee->user->is_active,
                    // An unused reset token is the only honest evidence that a
                    // set-password link is still outstanding: Laravel deletes
                    // the row the moment the password is chosen.
                    'password_link_sent_at' => $passwordLinkSentAt?->timezone(config('app.timezone'))->format('j M Y, g:i a'),
                    'password_link_expired' => $passwordLinkSentAt?->addMinutes($linkLifetime)->isPast() ?? false,
                ] : null,
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
                'referred_by' => $employee->referred_by,
                // The profile photo is an attachment in its own 'photo'
                // collection - singleton, so replacing it never accumulates
                // stale files - and it is EXCLUDED from the documents list
                // below, because a face is not a certificate.
                'photo_url' => $employee->attachmentInCollection('photo')?->url(),
                'has_signature' => $employee->attachmentInCollection('signature') !== null,
                // the files themselves goes through attachments.show, which
                // enforces the Employee's canAccessAttachments gate on top of
                // the business scope.
                'documents' => $employee->attachments
                    ->reject(fn (Attachment $document) => $document->collection === 'photo')
                    ->values()
                    ->map(fn (Attachment $document) => [
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
            // Creating a login is two acts (a user, and the join to this
            // record), so the card only appears for someone allowed both.
            'can_manage_login' => $request->user()->can('foundation.users.manage'),
            'password_link_lifetime' => $mailer->lifetimeLabel(),
            'transitionable_statuses' => collect($employee->transitionableStatuses())
                ->map(fn (EmployeeStatus $status) => ['value' => $status->value, 'label' => $status->label()]),
            // Probation: the confirm button and the due date it argues from.
            'can_confirm' => $employee->isOnProbation(),
            'probation_due' => $employee->isOnProbation() ? $employee->probationDueOn()?->toDateString() : null,
            'can_separate' => $employee->canSeparate(),
            // ⭐ Corrections wave 3 (24 Oct 2026): Delete (an unused draft) or
            // Undo separation, from EmployeeActionPolicy, and the reasons.
            'corrections' => [
                'actions' => app(EmployeeActionPolicy::class)->actionsFor($employee, $request->user())->toArray(),
                'reasons' => HrReasons::for(['deletion', 'reversal']),
            ],
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
                ->where('id', '!=', $employee->id)->orderBy('employee_code')->get()
                ->map(fn (Employee $candidate) => ['id' => $candidate->id, 'name' => $candidate->fullName().' ('.$candidate->employee_code.')']),
            'document_types' => collect(EmployeeDocumentType::cases())
                ->map(fn (EmployeeDocumentType $type) => ['value' => $type->value, 'label' => $type->label()]),
            // The record's own story (Module 1, Slice 4).
            'timeline' => RecordTimeline::for($employee),
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
            // SAL-08: the method varies per employee, and payroll validation
            // needs it to know which account details it should be insisting on.
            'payment_methods' => SalaryPaymentMethod::options(),
        ]);
    }

    public function update(Request $request, Employee $employee, UpdateEmployee $action): RedirectResponse
    {
        $this->authorize('hrm.employees.manage');
        abort_unless($employee->assignments()->exists(), 404);

        $data = $request->validate(Employee::masterFieldRules($employee));

        $action->handle($employee, $data);

        return redirect()->route('hrm.employees.show', $employee)->with('success', 'Employee details updated.');
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

    /**
     * ⭐ 27 Sep 2026 (Joy): change the email an employee signs in with. The
     * login IS the email, so this changes how they get in; the password is
     * untouched, so they keep the one they set. Owner/HR only, the same gate as
     * creating the login in the first place.
     */
    public function updateLoginEmail(Request $request, Employee $employee): RedirectResponse
    {
        $this->authorize('hrm.employees.manage');
        $this->authorize('foundation.users.manage');

        abort_unless($employee->assignments()->exists(), 404);

        if ($employee->user === null) {
            return back()->with('error', "{$employee->fullName()} has no login yet. Create one first.");
        }

        $data = $request->validate([
            'email' => [
                'required', 'string', 'lowercase', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($employee->user->id),
            ],
        ]);

        $employee->user->update(['email' => $data['email']]);

        return back()->with('success', "Login email changed to {$data['email']}. {$employee->fullName()} now signs in with it; their password is unchanged.");
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

    /**
     * Creates this employee's login and emails them a link to set their own
     * password, in one press.
     *
     * Two permissions, because it is two acts: a user account exists after it
     * (foundation.users.manage) and an employee record is joined to it
     * (hrm.employees.manage). Splitting them across two screens is what left
     * every account here unlinked and every self-service screen empty.
     */
    /**
     * Grant or withdraw standing permission to clock in from away from the office.
     *
     * ⚠⚠ THIS IS THE APPROVAL PHASE JOY ASKED FOR. Until it is granted, a person
     * clocking in from outside their office geofence raises a punch that needs
     * review; once granted, the punch is still located and still shows its
     * distance, but nobody is asked to approve a field salesman for doing his
     * job every single morning.
     *
     * ⚠ It does NOT authorise the day. The attendance engine decides Present,
     * Late or Absent from the punches exactly as before - this only settles
     * whether being somewhere else is a question.
     */
    public function setRemoteClockIn(Request $request, Employee $employee): RedirectResponse
    {
        $this->authorize('hrm.employees.manage');

        abort_unless($employee->assignments()->exists(), 404);

        $data = $request->validate([
            'may_clock_in_remotely' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:200'],
        ]);

        if ($employee->status === EmployeeStatus::Separated) {
            return back()->with('error', "{$employee->fullName()} has left, so their clock-in permissions are not changed.");
        }

        $granting = (bool) $data['may_clock_in_remotely'];

        $employee->update([
            'may_clock_in_remotely' => $granting,
            // ⚠ Cleared on withdrawal rather than left behind: "granted by X on
            // the 3rd" sitting under a permission nobody holds any more reads
            // as though they still hold it.
            'remote_clock_in_granted_by' => $granting ? $request->user()->id : null,
            'remote_clock_in_granted_at' => $granting ? now() : null,
            'remote_clock_in_reason' => $granting ? (($data['reason'] ?? null) ?: null) : null,
        ]);

        return back()->with('success', $granting
            ? "{$employee->fullName()} may now clock in from outside the office."
            : "{$employee->fullName()} may no longer clock in from outside the office.");
    }

    public function createLoginAccount(
        Request $request,
        Employee $employee,
        CreateEmployeeLogin $action,
        SendPasswordSetupLink $mailer,
    ): RedirectResponse {
        $this->authorize('hrm.employees.manage');
        $this->authorize('foundation.users.manage');
        // ⚠ The same tenancy guard every other action here has. Without it a
        // manager in one business could give a login, joined to THEIR
        // business, to somebody employed only by another.
        abort_unless($employee->assignments()->exists(), 404);

        if ($employee->user_id !== null) {
            return back()->with('error', "{$employee->fullName()} already has a login. Send them a new password link instead.");
        }

        if ($employee->status === EmployeeStatus::Separated) {
            return back()->with('error', "{$employee->fullName()} has left. A separated employee is not given a new way in.");
        }

        $data = $request->validate([
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255'],
        ]);

        $result = $action->handle($employee, $data['email'], app(BusinessContext::class)->id());

        $status = $mailer->handle($result['user']);

        if ($status !== Password::RESET_LINK_SENT) {
            // The account and the join are real and saved; only the email
            // failed. Say exactly that, or the next person presses the button
            // again and wonders why it refuses.
            return back()->with('error', "Login created for {$employee->fullName()}, but the email to {$data['email']} did not go out. Send the password link again from this page.");
        }

        $message = "Login created for {$employee->fullName()} and a password link emailed to {$data['email']}. It is valid for {$mailer->lifetimeLabel()}.";

        if (! $result['role_assigned'] && ! $result['reused_existing_account']) {
            $message .= ' They hold no role yet: set one on Settings, Team.';
        }

        return back()->with('success', $message);
    }

    /**
     * End probation (Joy's Q2/Q3 decisions): flips to permanent and grants the
     * pro-rated permanent leave entitlements in the same action, so the flip
     * and the money-adjacent grant can never happen separately.
     */
    public function confirm(Request $request, Employee $employee, ConfirmEmployee $action): RedirectResponse
    {
        $this->authorize('hrm.employees.manage');
        abort_unless($employee->assignments()->exists(), 404);

        if (! $employee->isOnProbation()) {
            return back()->with('error', 'Only an active employee on probation can be confirmed.');
        }

        $data = $request->validate([
            'confirmation_date' => ['required', 'date'],
        ]);

        $action->handle($employee, $data['confirmation_date']);

        return back()->with('success', "{$employee->fullName()} confirmed as permanent; this year's leave granted pro-rata.");
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

        // ⚠⚠ The one structural failure a hierarchy has: a chain that circles
        // (A answers to B answers to A). This guard was missing from the day
        // supervisor_id shipped; the org chart made the graph visible and the
        // gap with it (Module 1, Slice 1).
        if (($data['supervisor_id'] ?? null) !== null
            && Employee::wouldCreateReportingCycle($employee, (int) $data['supervisor_id'])) {
            return back()->withErrors([
                'supervisor_id' => 'This would make the reporting chain circle back on itself.',
            ]);
        }

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

    /**
     * ⭐ Corrections wave 3 (24 Oct 2026): a reason is required and the deletion
     * is logged on the employee (RemoveEmployeeDocument). It goes to the
     * Recycle bin once attachments are Recyclable (the files builder's part).
     */
    public function destroyDocument(Request $request, Employee $employee, Attachment $attachment, RemoveEmployeeDocument $action): RedirectResponse
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

        $request->validate($this->actionReasonRules('deletion'), $this->actionReasonMessages());

        $toBin = $action->handle($employee, $attachment, $request->user(), $this->actionReason($request));

        return back()->with('success', $toBin ? 'Document moved to the Recycle bin. The Owner can restore it.' : 'Document removed. The reason is in the History of this employee.');
    }

    /**
     * ⭐ Corrections wave 3 (24 Oct 2026, FINAL-PLAN 3.4): an unused draft
     * employee to the Recycle bin (DeleteDraftEmployee); the Owner restores.
     */
    public function destroy(Request $request, Employee $employee, DeleteDraftEmployee $action): RedirectResponse
    {
        $this->authorize('hrm.employees.manage');
        abort_unless($employee->assignments()->exists(), 404);
        $request->validate($this->actionReasonRules('deletion'), $this->actionReasonMessages());

        try {
            $action->handle($employee, $request->user(), $this->actionReason($request));
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('hrm.employees.index')->with('success', "{$employee->fullName()} moved to the Recycle bin. The Owner can restore them.");
    }

    /**
     * ⭐ Corrections wave 3 (24 Oct 2026, FINAL-PLAN 3.4): Undo separation
     * before the final pay. HR asks; a second person confirms from the
     * approvals inbox (UndoSeparation). Nothing changes until then.
     */
    public function undoSeparation(Request $request, Employee $employee, UndoSeparation $action): RedirectResponse
    {
        $this->authorize('hrm.employees.manage');
        abort_unless($employee->assignments()->exists(), 404);
        $request->validate($this->actionReasonRules('reversal'), $this->actionReasonMessages());

        try {
            $action->handle($employee, $request->user(), $this->actionReason($request));
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Undoing the separation now waits for a second person. Nothing changes until they approve it; the ERP login stays off until somebody switches it on under Users.');
    }

    /**
     * The profile photo (Joy's employee-details reorganisation, 23 Aug 2026).
     *
     * Same mechanics as the business logo: a singleton attachment in its own
     * collection, so replacing it never accumulates stale files. Kept apart
     * from documents because a face is not a certificate - it has no expiry,
     * no category, and it renders at the top of the page rather than in a
     * list.
     */
    public function uploadPhoto(Request $request, Employee $employee, StoreAttachment $action): RedirectResponse
    {
        $this->authorize('hrm.employees.manage');
        abort_unless($employee->assignments()->exists(), 404);

        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $action->handle(
            attachable: $employee,
            file: $request->file('photo'),
            collection: 'photo',
            uploadedBy: $request->user(),
            singleton: true,
        );

        return back()->with('success', 'Photo updated.');
    }

    /**
     * The digital signature (Joy, 3 Sep 2026): one PNG per employee, drawn
     * onto reports above their Prepared/Checked/Approved line when the
     * signatures box is ticked. Same shape as the photo: singleton, private
     * disk, HR-managed.
     */
    public function uploadSignature(Request $request, Employee $employee, StoreAttachment $action): RedirectResponse
    {
        $this->authorize('hrm.employees.manage');
        abort_unless($employee->assignments()->exists(), 404);

        $request->validate([
            'signature' => ['required', 'image', 'mimes:png,webp', 'max:1024'],
        ]);

        $action->handle(
            attachable: $employee,
            file: $request->file('signature'),
            collection: 'signature',
            uploadedBy: $request->user(),
            singleton: true,
        );

        return back()->with('success', 'Signature saved. Reports can now sign for this person.');
    }

    public function destroySignature(Employee $employee, DeleteAttachment $action): RedirectResponse
    {
        $this->authorize('hrm.employees.manage');

        // ⭐ 27 Sep 2026 (HRM audit): confine to the current business.
        abort_unless($employee->assignments()->exists(), 404);

        $signature = $employee->attachments()->where('collection', 'signature')->first();
        abort_if($signature === null, 404);

        $action->handle($signature);

        return back()->with('success', 'Signature removed.');
    }

    public function destroyPhoto(Employee $employee, DeleteAttachment $action): RedirectResponse
    {
        $this->authorize('hrm.employees.manage');
        abort_unless($employee->assignments()->exists(), 404);

        $photo = $employee->attachmentInCollection('photo');

        if ($photo !== null) {
            $action->handle($photo);
        }

        return back()->with('success', 'Photo removed.');
    }
}
