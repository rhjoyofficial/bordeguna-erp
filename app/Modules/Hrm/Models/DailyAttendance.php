<?php

namespace App\Modules\Hrm\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Hrm\Enums\AttendanceConsequence;
use App\Modules\Hrm\Enums\AttendanceStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One processed employee-day. Derived data — recalculable by design, unlike the
 * raw punches it was computed from.
 */
class DailyAttendance extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'business_id',
        'employee_id',
        'attendance_date',
        'status',
        'consequence',
        'first_in',
        'last_out',
        'worked_minutes',
        'break_minutes',
        'payable_minutes',
        'late_minutes',
        'early_leave_minutes',
        'grace_window_key',
        'occurrence_number',
        'is_provisional',
        'holds_payroll',
        'flags',
        'calculation',
        'policy_id',
        'policy_version',
        'shift_id',
        'finalized_at',
        'finalized_by',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'attendance_date' => 'date',
            'status' => AttendanceStatus::class,
            'consequence' => AttendanceConsequence::class,
            'first_in' => 'datetime',
            'last_out' => 'datetime',
            'worked_minutes' => 'integer',
            'break_minutes' => 'integer',
            'payable_minutes' => 'integer',
            'late_minutes' => 'integer',
            'early_leave_minutes' => 'integer',
            'occurrence_number' => 'integer',
            'is_provisional' => 'boolean',
            'holds_payroll' => 'boolean',
            'flags' => 'array',
            'calculation' => 'array',
            'policy_version' => 'integer',
            'finalized_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(AttendancePolicy::class, 'policy_id');
    }

    public function finalizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    /**
     * A finalised day must not be silently recomputed — that is a human's
     * decision made on the record (POL-06). Reprocessing skips these unless
     * explicitly told otherwise.
     */
    public function isFinalized(): bool
    {
        return $this->finalized_at !== null;
    }

    public function scopeOnDate(Builder $query, Carbon|string $date): Builder
    {
        return $query->whereDate('attendance_date', Carbon::parse($date)->toDateString());
    }

    public function scopeNeedingReview(Builder $query): Builder
    {
        return $query->where('is_provisional', true);
    }

    /**
     * How many times this employee already hit a given lateness band earlier in
     * the same month — the running counter POL-03's ladders escalate on.
     * Counted strictly *before* the date being processed so reprocessing a day
     * cannot count itself.
     *
     * @return array<string, int>
     */
    public static function priorOccurrences(int $employeeId, Carbon $date): array
    {
        return static::query()
            ->where('employee_id', $employeeId)
            ->whereNotNull('grace_window_key')
            // whereDate on both bounds, not whereBetween: `attendance_date` is a
            // date cast stored as "Y-m-d 00:00:00", so a plain string upper
            // bound of "Y-m-d" compares as less than the stored value and
            // silently drops that day — which would under-count the ladder.
            ->whereDate('attendance_date', '>=', $date->copy()->startOfMonth()->toDateString())
            ->whereDate('attendance_date', '<', $date->toDateString())
            ->selectRaw('grace_window_key, count(*) as total')
            ->groupBy('grace_window_key')
            ->pluck('total', 'grace_window_key')
            ->map(fn ($total) => (int) $total)
            ->all();
    }
}
