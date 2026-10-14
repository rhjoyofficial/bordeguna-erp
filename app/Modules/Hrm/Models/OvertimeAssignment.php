<?php

namespace App\Modules\Hrm\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\Approvable;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Contracts\MarksSelfApproval;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Hrm\Support\ApprovedForOwnEmployee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * An authorised stint of overtime (ADJ-01, ADJ-03, ADJ-04).
 *
 * ADJ-01 ticked "only approved overtime assignment", so hours are authorised
 * rather than inferred from punches — which is fortunate, because deriving them
 * would mean reopening the attendance engine that Phase 2 closed.
 *
 * ADJ-04 splits the outcome in two. An ordinary stint is priced by payroll; a
 * holiday or off-day stint is compensated in leave instead (Correction C8b) and
 * never reaches a payslip. `is_holiday_work` is what decides which, and the two
 * are mutually exclusive on purpose: paying for the hours *and* crediting the
 * leave would compensate the same work twice.
 */
class OvertimeAssignment extends Model implements MarksSelfApproval
{
    // Rule #22 (14 Oct 2026): approving your own one needs a reason and is marked.
    use Approvable, ApprovedForOwnEmployee, BelongsToBusiness, HasFactory, LogsActivity;

    protected $fillable = [
        'business_id',
        'employee_id',
        'work_date',
        'hours',
        'is_holiday_work',
        'compensatory_days',
        'reason',
        'requested_by',
        'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date:Y-m-d',
            'hours' => 'decimal:2',
            'is_holiday_work' => 'boolean',
            'compensatory_days' => 'decimal:2',
            'applied_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['employee_id', 'work_date', 'hours', 'is_holiday_work', 'compensatory_days', 'reason', 'applied_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isApproved(): bool
    {
        return $this->approvalRequest?->status === ApprovalStatus::Approved;
    }

    public function isApplied(): bool
    {
        return $this->applied_at !== null;
    }

    public function scopeApplied(Builder $query): Builder
    {
        return $query->whereNotNull('applied_at');
    }

    /**
     * The stints payroll prices. Holiday work is deliberately excluded — ADJ-04
     * pays it in leave, and the credit was already posted on approval.
     */
    public function scopePayable(Builder $query): Builder
    {
        return $query->applied()->where('is_holiday_work', false);
    }

    public function scopeInPeriod(Builder $query, Carbon|string $start, Carbon|string $end): Builder
    {
        return $query
            ->whereDate('work_date', '>=', Carbon::parse($start)->toDateString())
            ->whereDate('work_date', '<=', Carbon::parse($end)->toDateString());
    }

    public function summaryLine(): string
    {
        return sprintf(
            '%s — %s hour%s of %s overtime on %s',
            $this->employee?->fullName(),
            rtrim(rtrim(number_format((float) $this->hours, 2), '0'), '.'),
            (float) $this->hours === 1.0 ? '' : 's',
            $this->is_holiday_work ? 'holiday' : 'normal',
            Carbon::parse($this->work_date)->toDateString(),
        );
    }
}
