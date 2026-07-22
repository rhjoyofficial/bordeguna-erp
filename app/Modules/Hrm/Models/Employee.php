<?php

namespace App\Modules\Hrm\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\HasAttachments;
use App\Modules\Foundation\Contracts\ControlsAttachmentAccess;
use App\Modules\Hrm\Enums\EmployeeStatus;
use App\Modules\Hrm\Enums\EmploymentType;
use App\Modules\Hrm\Enums\MfsProvider;
use App\Modules\Hrm\Enums\SeparationType;
use App\Modules\Hrm\Support\SelfScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A central Bor De Guna employee (ORG-01). Separate from User (EMP-01): most
 * employees have no ERP login, and one optionally links to a User when they
 * need access. Not business-scoped — belonging is expressed through
 * effective-dated assignments.
 */
class Employee extends Model implements ControlsAttachmentAccess
{
    use HasAttachments, HasFactory, LogsActivity;

    protected $fillable = [
        'employee_code',
        'user_id',
        'first_name',
        'last_name',
        'employment_type',
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
        'confirmation_date',
        'contract_end_date',
        'bank_account_name',
        'bank_account_number',
        'bank_name',
        'bank_branch',
        'bank_routing_number',
        'mfs_provider',
        'mfs_account_number',
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
        'first_name', 'last_name', 'employment_type',
        'personal_email', 'phone', 'present_address', 'permanent_address',
        'national_id', 'birth_certificate_no', 'date_of_birth',
        'joining_date', 'confirmation_date', 'contract_end_date',
        'emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relationship',
        'bank_account_name', 'bank_account_number', 'bank_name', 'bank_branch', 'bank_routing_number',
        'mfs_provider', 'mfs_account_number',
    ];

    protected function casts(): array
    {
        return [
            'employment_type' => EmploymentType::class,
            'status' => EmployeeStatus::class,
            'separation_type' => SeparationType::class,
            'mfs_provider' => MfsProvider::class,
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
}
