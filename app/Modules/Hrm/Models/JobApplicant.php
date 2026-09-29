<?php

namespace App\Modules\Hrm\Models;

use App\Modules\Crm\Support\PhoneIdentity;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Concerns\HasAttachments;
use App\Modules\Hrm\Enums\ApplicantStage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Somebody who applied for a role.
 *
 * ⚠⚠ HIRING SOMEBODY HERE DOES NOT CREATE AN EMPLOYEE. `hired_employee_id`
 * points at a record somebody made in HRM, because a joining date, an employment
 * type, a probation length and a salary structure are not things a recruitment
 * screen knows, and guessing them puts a wrong number into the first payroll.
 */
class JobApplicant extends Model
{
    use BelongsToBusiness, HasAttachments, LogsActivity;

    protected $fillable = [
        'business_id', 'job_opening_id', 'name', 'phone', 'phone_normalized', 'email',
        'source', 'expected_salary', 'stage', 'note', 'outcome_reason',
        'hired_employee_id', 'applied_on', 'created_by',
    ];

    /** ⚠ The database default repeated, so a fresh instance has a real stage. */
    protected $attributes = ['stage' => 'applied'];

    protected function casts(): array
    {
        return [
            'stage' => ApplicantStage::class,
            'applied_on' => 'date:Y-m-d',
            'expected_salary' => 'decimal:2',
        ];
    }

    /**
     * ⚠ Maintained here rather than at each call site, exactly as Contact does.
     * There are three ways an applicant is created and a matching column only
     * two of them fill is worse than none.
     */
    protected static function booted(): void
    {
        static::saving(function (self $applicant) {
            $applicant->phone_normalized = PhoneIdentity::normalize($applicant->phone);
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'stage', 'outcome_reason', 'hired_employee_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function opening(): BelongsTo
    {
        return $this->belongsTo(JobOpening::class, 'job_opening_id');
    }

    public function hiredEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'hired_employee_id');
    }

    /**
     * ⚠ People who look like this one. WARNS, never blocks: the same person
     * applying for two roles is normal, and a family sharing a number is as real
     * here as it is in CRM (C-CRM-12, C-CRM-13).
     */
    public function possibleDuplicates(): Builder
    {
        return static::query()
            ->where('id', '!=', $this->id ?? 0)
            ->when(
                $this->phone_normalized !== null,
                fn (Builder $q) => $q->where('phone_normalized', $this->phone_normalized),
                fn (Builder $q) => $q->whereRaw('1 = 0'),
            );
    }
}
