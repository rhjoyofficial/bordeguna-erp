<?php

namespace App\Modules\Hrm\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Concerns\EffectiveDated;
use App\Modules\Foundation\Models\Department;
use App\Modules\Hrm\Attendance\Policy\PolicyConfig;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A versioned attendance rule set (POL-12).
 *
 * Effective-dated with the same P1 concern schedules use, so a day is always
 * judged by the policy in force on that day. A revision opens a new row instead
 * of editing this one — that is what makes "changes apply prospectively only,
 * and a closed period never recalculates" true by construction rather than by
 * discipline.
 */
class AttendancePolicy extends Model
{
    use BelongsToBusiness, EffectiveDated, HasFactory, LogsActivity;

    protected $fillable = [
        'business_id',
        'department_id',
        'name',
        'version',
        'config',
        'effective_from',
        'effective_to',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'config' => 'array',
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'version', 'config', 'effective_from', 'effective_to'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function toConfig(): PolicyConfig
    {
        return PolicyConfig::fromArray($this->config ?? []);
    }

    /**
     * The policy that governed a given date for a given department, or null if
     * none was in force yet.
     *
     * ⚠ Most specific wins, and it wins WHOLE. A department policy is not merged
     * over the business default: a half-inherited rule set is one nobody can read
     * off a single row, and "which policy judged this day" has to stay answerable
     * from `policy_id` alone years later. Where a department has its own order,
     * that order is the policy; everywhere else the business default applies.
     */
    public static function inForceOn(Carbon|string $date, ?int $departmentId = null): ?self
    {
        $candidates = static::activeOn($date)
            ->when(
                $departmentId !== null,
                fn ($query) => $query->where(fn ($q) => $q->whereNull('department_id')->orWhere('department_id', $departmentId)),
                fn ($query) => $query->whereNull('department_id'),
            )
            // A department row sorts ahead of the business-wide one; after that
            // the newest and highest version wins, exactly as before.
            ->orderByRaw('department_id is null')
            ->orderByDesc('effective_from')
            ->orderByDesc('version');

        return $candidates->first();
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
