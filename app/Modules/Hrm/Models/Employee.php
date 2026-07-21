<?php

namespace App\Modules\Hrm\Models;

use App\Models\User;
use App\Modules\Hrm\Enums\EmployeeStatus;
use App\Modules\Hrm\Enums\EmploymentType;
use App\Modules\Hrm\Enums\SeparationType;
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
class Employee extends Model
{
    use HasFactory, LogsActivity;

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
        'national_id',
        'date_of_birth',
        'joining_date',
    ];

    protected function casts(): array
    {
        return [
            'employment_type' => EmploymentType::class,
            'status' => EmployeeStatus::class,
            'separation_type' => SeparationType::class,
            'separation_date' => 'date',
            'date_of_birth' => 'date',
            'joining_date' => 'date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['first_name', 'last_name', 'employment_type', 'status', 'separation_type', 'user_id'])
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

    public function assignments(): HasMany
    {
        return $this->hasMany(EmployeeAssignment::class);
    }

    /**
     * The employee's current primary assignment (the active one, if any).
     */
    public function currentAssignment(): ?EmployeeAssignment
    {
        return $this->assignments()->where('is_primary', true)->active()->latest('effective_from')->first();
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }
}
