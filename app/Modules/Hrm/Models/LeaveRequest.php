<?php

namespace App\Modules\Hrm\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\Approvable;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Hrm\Enums\LeaveDayPortion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A leave request (LEV-04/05/06/07). Approvable, so Manager → HR routing rides
 * on the existing workflow engine.
 *
 * `covered_dates` holds the working days actually charged after LEV-06 removed
 * holidays and weekly offs, so the day count is auditable rather than something
 * to re-derive later against a calendar that may since have changed.
 */
class LeaveRequest extends Model
{
    use Approvable, BelongsToBusiness, HasFactory, LogsActivity;

    protected $fillable = [
        'business_id',
        'employee_id',
        'leave_type_id',
        'start_date',
        'end_date',
        'day_portion',
        'days',
        'unpaid_days',
        'reason',
        'covered_dates',
        'requested_by',
        'applied_at',
        'paid_override_by',
        'paid_override_at',
        'paid_override_reason',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'day_portion' => LeaveDayPortion::class,
            'days' => 'decimal:2',
            'unpaid_days' => 'decimal:2',
            'covered_dates' => 'array',
            'applied_at' => 'datetime',
            'paid_override_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['employee_id', 'leave_type_id', 'start_date', 'end_date', 'day_portion', 'days', 'unpaid_days', 'reason', 'applied_at', 'paid_override_by', 'paid_override_at', 'paid_override_reason'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function paidOverrideBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_override_by');
    }

    /**
     * Whether the authority has paid the unpaid days of this request as a
     * company-financed grant (Joy, 27 Sep 2026).
     */
    public function isPaidByAuthority(): bool
    {
        return $this->paid_override_at !== null;
    }

    public function isApproved(): bool
    {
        return $this->approvalRequest?->status === ApprovalStatus::Approved;
    }

    public function isApplied(): bool
    {
        return $this->applied_at !== null;
    }

    /**
     * Whether an approved leave covers a given date — the seam the attendance
     * pipeline reads when it gathers a day.
     */
    public function covers(Carbon|string $date): bool
    {
        return in_array(Carbon::parse($date)->toDateString(), $this->covered_dates ?? [], true);
    }

    public function scopeApplied(Builder $query): Builder
    {
        return $query->whereNotNull('applied_at');
    }

    public function summaryLine(): string
    {
        $start = Carbon::parse($this->start_date)->toDateString();
        $end = Carbon::parse($this->end_date)->toDateString();
        $range = $start === $end ? $start : "{$start} → {$end}";

        return sprintf(
            '%s — %s, %s day%s (%s)',
            $this->employee?->fullName(),
            $this->leaveType?->name,
            rtrim(rtrim(number_format((float) $this->days, 2), '0'), '.'),
            (float) $this->days === 1.0 ? '' : 's',
            $range,
        );
    }
}
