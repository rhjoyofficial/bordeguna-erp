<?php

namespace App\Modules\Hrm\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\HasAttachments;
use App\Modules\Foundation\Contracts\ControlsAttachmentAccess;
use App\Modules\Foundation\Contracts\HandlesChangeRequest;
use App\Modules\Hrm\Actions\UpdateEmployee;
use App\Modules\Hrm\Enums\EmployeeStatus;
use App\Modules\Hrm\Enums\EmploymentType;
use App\Modules\Hrm\Enums\MfsProvider;
use App\Modules\Hrm\Enums\SalaryPaymentMethod;
use App\Modules\Hrm\Enums\SeparationType;
use App\Modules\Hrm\Support\DateOfBirthRule;
use App\Modules\Hrm\Support\SelfScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A central Bor de Guna employee (ORG-01). Separate from User (EMP-01): most
 * employees have no ERP login, and one optionally links to a User when they
 * need access. Not business-scoped — belonging is expressed through
 * effective-dated assignments.
 */
class Employee extends Model implements ControlsAttachmentAccess, HandlesChangeRequest
{
    use HasAttachments, HasFactory, LogsActivity;

    protected $fillable = [
        'employee_code',
        'biometric_enroll_id',
        'may_clock_in_remotely',
        'remote_clock_in_granted_by',
        'remote_clock_in_granted_at',
        'remote_clock_in_reason',
        'user_id',
        'first_name',
        'last_name',
        'employment_type',
        'probation_months',
        'status',
        'separation_type',
        'separation_date',
        'separation_reason',
        'personal_email',
        'phone',
        'present_address',
        'permanent_address',
        'national_id',
        'birth_certificate_no',
        'date_of_birth',
        'joining_date',
        'emergency_contact_name',
        'emergency_contact_phone',
        'emergency_contact_relationship',
        'referred_by',
        'blood_group',
        'confirmation_date',
        'contract_end_date',
        'bank_account_name',
        'bank_account_number',
        'bank_name',
        'bank_branch',
        'bank_routing_number',
        'mfs_provider',
        'mfs_account_number',
        'salary_payment_method',
    ];

    /**
     * The EMP-04 master fields an HR user may edit through the employee edit
     * form (identity is immutable: employee_code, user_id, status, separation
     * and the effective-dated assignment are changed through their own
     * dedicated flows, not this list). This is the single source of truth for
     * "what counts as editable master data" — the future generic Change Request
     * flow validates a self-service request against exactly this set.
     *
     * @var list<string>
     */
    public const EDITABLE_FIELDS = [
        'first_name', 'last_name', 'employment_type', 'probation_months', 'biometric_enroll_id',
        'personal_email', 'phone', 'present_address', 'permanent_address',
        'national_id', 'birth_certificate_no', 'date_of_birth',
        'joining_date', 'confirmation_date', 'contract_end_date',
        'emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relationship', 'referred_by',
        'blood_group',
        'bank_account_name', 'bank_account_number', 'bank_name', 'bank_branch', 'bank_routing_number',
        'mfs_provider', 'mfs_account_number', 'salary_payment_method',
    ];

    /**
     * Who granted this person standing permission to clock in remotely.
     *
     * ⚠ Null both when nobody has granted it and when it was withdrawn: the
     * grant details are cleared with the permission itself.
     */
    public function remoteClockInGrantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'remote_clock_in_granted_by');
    }

    protected function casts(): array
    {
        return [
            'employment_type' => EmploymentType::class,
            'may_clock_in_remotely' => 'boolean',
            'remote_clock_in_granted_at' => 'datetime',
            'probation_months' => 'integer',
            'status' => EmployeeStatus::class,
            'separation_type' => SeparationType::class,
            'mfs_provider' => MfsProvider::class,
            'salary_payment_method' => SalaryPaymentMethod::class,
            'separation_date' => 'date',
            'date_of_birth' => 'date',
            'joining_date' => 'date',
            'confirmation_date' => 'date',
            'contract_end_date' => 'date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        // Full audit history over every master field HR can change, plus the
        // lifecycle fields driven by their own flows. Sensitive raw values
        // (NID, bank, MFS) are logged too — a changed bank account is exactly
        // the trail you want — so encryption-at-rest of the activity log is a
        // W4 (PII) follow-up, tracked centrally rather than by dropping fields.
        return LogOptions::defaults()
            ->logOnly([...self::EDITABLE_FIELDS, 'status', 'separation_type', 'user_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * Lifecycle state machine (EMP-06). Separation is handled by its own action
     * (it carries a type/date/reason and side effects), so it's not listed here.
     *
     * @return array<int, EmployeeStatus>
     */
    public function transitionableStatuses(): array
    {
        return match ($this->status) {
            EmployeeStatus::Draft => [EmployeeStatus::Active],
            EmployeeStatus::Active => [EmployeeStatus::OnLeave, EmployeeStatus::Suspended],
            EmployeeStatus::OnLeave, EmployeeStatus::Suspended => [EmployeeStatus::Active],
            EmployeeStatus::Separated => [],
        };
    }

    public function canSeparate(): bool
    {
        return $this->status !== EmployeeStatus::Separated;
    }

    /**
     * The company default probation length. A different length for one person
     * is data on their record (probation_months), never a second constant.
     */
    public const DEFAULT_PROBATION_MONTHS = 6;

    public function isOnProbation(): bool
    {
        return $this->employment_type === EmploymentType::Probation
            && $this->status !== EmployeeStatus::Separated;
    }

    /**
     * When this employee's probation is due to end: joining date plus their
     * own probation_months, or the 6-month default. Null when the joining
     * date is unknown - a due date invented from nothing would only look
     * authoritative. This is a DUE date; becoming permanent is the explicit
     * confirm action, which also grants the permanent leave entitlements.
     */
    public function probationDueOn(): ?Carbon
    {
        if ($this->joining_date === null) {
            return null;
        }

        return $this->joining_date->copy()->addMonthsNoOverflow($this->probation_months ?? self::DEFAULT_PROBATION_MONTHS);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The (central) employee record linked to a given user, if any — the entry
     * point for Employee Self-Service ("my profile"). Not business-scoped: an
     * employee is central, so this resolves across whatever business the user is
     * currently working in.
     */
    public static function forUser(User $user): ?self
    {
        return static::where('user_id', $user->id)->first();
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(EmployeeAssignment::class);
    }

    /**
     * The department this employee sat in ON a given date.
     *
     * ⚠ Not `currentAssignment()`. Attendance is reprocessed for days long past,
     * and a department-scoped policy judged by today's department would silently
     * re-judge every day before a transfer under the new department's rules.
     * Same transfer-date tie-break as the current lookup: newest wins.
     */
    public function departmentIdOn(Carbon|string $date): ?int
    {
        return $this->assignments()->where('is_primary', true)->activeOn($date)
            ->orderByDesc('effective_from')->orderByDesc('id')
            ->value('department_id');
    }

    /**
     * The employee's current primary assignment (the active one, if any).
     */
    public function currentAssignment(): ?EmployeeAssignment
    {
        // Newest wins if a transfer leaves the old and new assignment overlapping
        // on the transfer date itself.
        return $this->assignments()->where('is_primary', true)->active()
            ->orderByDesc('effective_from')->orderByDesc('id')->first();
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    /**
     * An employee's documents can hold sensitive personal data (NID, bank),
     * so viewing them needs more than being in the right business: only HR
     * (whoever may manage employees) or the employee themselves (their linked
     * User) may read them. This is the same owner-or-permitted rule ESS uses
     * everywhere — delegated to the P3 self-scope primitive so it stays in one
     * place (see SelfScope).
     */
    public function canAccessAttachments(User $user): bool
    {
        return SelfScope::allows($user, $this, 'hrm.employees.manage');
    }

    /**
     * Human labels for the editable fields, used both on the HR forms and when
     * a change request describes itself in the approval inbox / ESS.
     *
     * @var array<string, string>
     */
    public const FIELD_LABELS = [
        'first_name' => 'First name',
        'last_name' => 'Last name',
        'employment_type' => 'Employment type',
        'probation_months' => 'Probation length (months)',
        'biometric_enroll_id' => 'Biometric enroll ID',
        'personal_email' => 'Personal email',
        'phone' => 'Phone',
        'present_address' => 'Present address',
        'permanent_address' => 'Permanent address',
        'national_id' => 'National ID',
        'birth_certificate_no' => 'Birth certificate no.',
        'date_of_birth' => 'Date of birth',
        'joining_date' => 'Joining date',
        'confirmation_date' => 'Confirmation date',
        'contract_end_date' => 'Contract end date',
        'emergency_contact_name' => 'Emergency contact name',
        'emergency_contact_phone' => 'Emergency contact phone',
        'emergency_contact_relationship' => 'Emergency contact relationship',
        'blood_group' => 'Blood group',
        'bank_account_name' => 'Bank account name',
        'bank_account_number' => 'Bank account number',
        'bank_name' => 'Bank name',
        'bank_branch' => 'Bank branch',
        'bank_routing_number' => 'Bank routing number',
        'mfs_provider' => 'MFS provider',
        'mfs_account_number' => 'MFS account number',
        'salary_payment_method' => 'Salary payment method',
    ];

    /**
     * Validation for the EMP-04 master fields — the single source of truth so the
     * HR edit form and an ESS Employee Update Request validate identically.
     *
     * @return array<string, mixed>
     */
    public static function masterFieldRules(?self $employee = null): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'employment_type' => ['required', new Enum(EmploymentType::class)],
            // Joy, 24 Aug 2026: probation is 6 months by default but "can vary
            // of employees sometimes" - blank means the default.
            'probation_months' => ['nullable', 'integer', 'min:1', 'max:24'],
            // The biometric device's Enroll ID (ATT-02). Unique across the whole
            // employee master: two people sharing one enroll ID would silently
            // merge their imported attendance.
            'biometric_enroll_id' => [
                'nullable', 'string', 'max:50',
                Rule::unique('employees', 'biometric_enroll_id')->ignore($employee?->id),
            ],
            'personal_email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'present_address' => ['nullable', 'string', 'max:1000'],
            'permanent_address' => ['nullable', 'string', 'max:1000'],
            'national_id' => ['nullable', 'string', 'max:100'],
            'birth_certificate_no' => ['nullable', 'string', 'max:100'],
            'date_of_birth' => DateOfBirthRule::rules(),
            'joining_date' => ['nullable', 'date'],
            'confirmation_date' => ['nullable', 'date'],
            'contract_end_date' => ['nullable', 'date'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:50'],
            'emergency_contact_relationship' => ['nullable', 'string', 'max:100'],
            // Free text, not an employee id: referrers are often outside the
            // company (a relative, a mill contact), and a staff dropdown would
            // refuse the most common case.
            'referred_by' => ['nullable', 'string', 'max:255'],
            // Free text rather than an enum: rare groups and local notations vary,
            // and refusing a real blood group at data entry is worse than storing it.
            'blood_group' => ['nullable', 'string', 'max:8'],
            'bank_account_name' => ['nullable', 'string', 'max:255'],
            'bank_account_number' => ['nullable', 'string', 'max:100'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_branch' => ['nullable', 'string', 'max:255'],
            'bank_routing_number' => ['nullable', 'string', 'max:100'],
            'mfs_provider' => ['nullable', new Enum(MfsProvider::class)],
            'mfs_account_number' => ['nullable', 'string', 'max:50'],
            'salary_payment_method' => ['nullable', new Enum(SalaryPaymentMethod::class)],
        ];
    }

    // --- HandlesChangeRequest (ESS Employee Update Requests) ------------------

    /**
     * The applier the generic ChangeRequest calls on approval. Delegates to the
     * same UpdateEmployee action the HR edit form uses, so an approved request
     * and a direct edit share one validated write path and one audit trail.
     */
    public function applyChangeRequest(array $changes): void
    {
        app(UpdateEmployee::class)->handle($this, $changes);
    }

    public function changeRequestSubjectLabel(): string
    {
        return $this->employee_code.' — '.$this->fullName();
    }

    public function describeChangeRequest(array $changes): array
    {
        return collect($changes)
            ->mapWithKeys(fn ($value, $field) => [
                self::FIELD_LABELS[$field] ?? $field => $value === null || $value === '' ? '—' : (string) $value,
            ])
            ->all();
    }
}
