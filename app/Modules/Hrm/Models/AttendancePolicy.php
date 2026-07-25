<?php

namespace App\Modules\Hrm\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Concerns\EffectiveDated;
use App\Modules\Hrm\Attendance\Policy\PolicyConfig;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
            'effective_from' => 'date',
            'effective_to' => 'date',
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
     * The policy that governed a given date, or null if none was in force yet.
     */
    public static function inForceOn(Carbon|string $date): ?self
    {
        return static::activeOn($date)
            ->orderByDesc('effective_from')
            ->orderByDesc('version')
            ->first();
    }
}
