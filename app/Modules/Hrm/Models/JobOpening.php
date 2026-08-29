<?php

namespace App\Modules\Hrm\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Models\Department;
use App\Modules\Foundation\Models\Designation;
use App\Modules\Hrm\Enums\JobOpeningStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A role the business is trying to fill.
 */
class JobOpening extends Model
{
    use BelongsToBusiness, LogsActivity;

    protected $fillable = [
        'business_id', 'title', 'department_id', 'designation_id', 'description',
        'openings', 'status', 'opened_on', 'closes_on', 'created_by',
    ];

    /** ⚠ The database defaults repeated on the model, this codebase's convention. */
    protected $attributes = ['status' => 'open', 'openings' => 1];

    protected function casts(): array
    {
        return [
            'status' => JobOpeningStatus::class,
            'opened_on' => 'date',
            'closes_on' => 'date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['title', 'status', 'openings', 'closes_on'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function applicants(): HasMany
    {
        return $this->hasMany(JobApplicant::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', JobOpeningStatus::Open);
    }

    /**
     * ⚠ How many of the wanted headcount are still unfilled. Two openings with
     * one hire is still an open role, and a screen that showed "filled" the
     * moment anybody was hired would close it early.
     */
    public function stillWanted(): int
    {
        return max(0, $this->openings - $this->applicants()->where('stage', 'hired')->count());
    }
}
