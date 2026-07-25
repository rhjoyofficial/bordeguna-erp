<?php

namespace App\Modules\Hrm\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A closed attendance period (COR-05). Once a date falls inside an active lock,
 * corrections for it need the audited override Correction C1 describes rather
 * than the normal request path.
 */
class AttendancePeriodLock extends Model
{
    use BelongsToBusiness, HasFactory, LogsActivity;

    protected $fillable = [
        'business_id',
        'period_start',
        'period_end',
        'note',
        'locked_at',
        'locked_by',
        'unlocked_at',
        'unlocked_by',
        'unlock_reason',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'locked_at' => 'datetime',
            'unlocked_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['period_start', 'period_end', 'note', 'locked_at', 'unlocked_at', 'unlock_reason'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function locker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    public function unlocker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'unlocked_by');
    }

    public function isActive(): bool
    {
        return $this->unlocked_at === null;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('unlocked_at');
    }

    /**
     * The active lock covering a date, if any.
     *
     * Bounds are compared with whereDate because both columns are date casts
     * stored as "Y-m-d 00:00:00" — a plain string comparison against "Y-m-d"
     * silently excludes the boundary day.
     */
    public static function covering(Carbon|string $date): ?self
    {
        $date = Carbon::parse($date)->toDateString();

        return static::active()
            ->whereDate('period_start', '<=', $date)
            ->whereDate('period_end', '>=', $date)
            ->first();
    }

    public static function isLocked(Carbon|string $date): bool
    {
        return static::covering($date) !== null;
    }
}
