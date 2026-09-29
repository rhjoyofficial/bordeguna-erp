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
 * One day's salary consequence, forgiven by a named person with a stated
 * reason (Joy, 24 Aug 2026).
 *
 * The day itself is untouched: the attendance engine's verdict (absent, late,
 * the occurrence number) stays on daily_attendances and on every screen.
 * Only payroll's PRICING consults this table - a waived absent day is still
 * not a worked day, it simply stops costing money.
 *
 * Keyed by (business, employee, date) rather than the daily row's id, the
 * attendance_corrections precedent: rows are rewritten in place today, but
 * this project has rebuilt attendance from raw punches twice in one day, and
 * the DAY is the identity that survives a rebuild.
 *
 * Undo revokes rather than deletes, so forgiveness granted and forgiveness
 * withdrawn are both permanently on record.
 */
class AttendanceWaiver extends Model
{
    use BelongsToBusiness, HasFactory, LogsActivity;

    protected $fillable = [
        'business_id',
        'employee_id',
        'attendance_date',
        'waived_status',
        'waived_consequence',
        'reason',
        'waived_by',
        'revoked_at',
        'revoked_by',
    ];

    protected function casts(): array
    {
        return [
            'attendance_date' => 'date:Y-m-d',
            'revoked_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['employee_id', 'attendance_date', 'waived_status', 'waived_consequence', 'reason', 'revoked_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function waiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waived_by');
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    /**
     * The date-cast trap (seventh appearance): attendance_date stores a time
     * component, so equality against "Y-m-d" is whereDate or nothing.
     */
    public function scopeOnDate(Builder $query, Carbon|string $date): Builder
    {
        return $query->whereDate('attendance_date', Carbon::parse($date)->toDateString());
    }

    /**
     * The active waiver covering one employee-day, if any.
     */
    public static function activeFor(int $employeeId, Carbon|string $date): ?self
    {
        return static::query()->active()
            ->where('employee_id', $employeeId)
            ->onDate($date)
            ->first();
    }
}
