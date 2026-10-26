<?php

namespace App\Modules\Hrm\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\Approvable;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Concerns\WithdrawsBySendingBack;
use App\Modules\Foundation\Contracts\WithdrawsFromApproval;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Models\ApprovalRequest;
use App\Modules\Hrm\Enums\MovementType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Manager-approved presence outside the normal pattern — field duty and
 * official work (POL-10), or work on a holiday/off-day (POL-09).
 *
 * Kept as a separate workflow from leave, per LEV-04: a part-day absence for an
 * errand is a movement, not a fraction of a leave day, and it draws on no
 * balance.
 */
class Movement extends Model implements WithdrawsFromApproval
{
    use Approvable, BelongsToBusiness, HasFactory, LogsActivity, WithdrawsBySendingBack;

    protected $fillable = [
        'business_id',
        'employee_id',
        'type',
        'movement_date',
        'from_time',
        'to_time',
        'place',
        'reason',
        'requested_by',
        'applied_at',
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'type' => MovementType::class,
            'movement_date' => 'date:Y-m-d',
            'applied_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['employee_id', 'type', 'movement_date', 'from_time', 'to_time', 'place', 'reason', 'applied_at', 'cancelled_at', 'cancel_reason'])
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

    public function isApplied(): bool
    {
        return $this->applied_at !== null;
    }

    /** ⭐ Corrections wave 3 (24 Oct 2026): cancelled after approval; the row stays. */
    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /** ⚠ In effect: applied and not cancelled (the attendance pipeline reads this). */
    public function scopeApplied(Builder $query): Builder
    {
        return $query->whereNotNull('applied_at')->whereNull('cancelled_at');
    }

    public function scopeOnDate(Builder $query, Carbon|string $date): Builder
    {
        return $query->whereDate('movement_date', Carbon::parse($date)->toDateString());
    }

    public function summaryLine(): string
    {
        $window = match (true) {
            // Early leave carries one time: when the person goes.
            $this->type?->isEarlyLeave() && $this->from_time !== null => ' leaving at '.substr((string) $this->from_time, 0, 5),
            $this->from_time && $this->to_time => sprintf(' %s–%s', substr((string) $this->from_time, 0, 5), substr((string) $this->to_time, 0, 5)),
            default => '',
        };

        return sprintf(
            '%s — %s on %s%s%s',
            $this->employee?->fullName(),
            $this->type->label(),
            Carbon::parse($this->movement_date)->toDateString(),
            $window,
            $this->place ? ' at '.$this->place : '',
        );
    }

    // Withdraw (B13, 24 Oct 2026): the sender pulls it back while it waits.

    protected function withdrawRefusal(User $by, ApprovalRequest $request): ?string
    {
        return $this->isApplied() ? 'This movement is already approved. Cancel it instead.' : null;
    }

    /** Closed, kept and marked: nothing was approved, so nothing is undone. */
    protected function withdrawn(User $by, ActionReason $reason): void
    {
        $this->forceFill(['cancelled_at' => now(), 'cancelled_by' => $by->id, 'cancel_reason' => $this->withdrawnNote($reason)])->save();
    }

    public function withdrawOutcome(): string
    {
        return 'cancelled';
    }

    public function withdrawLabel(): string
    {
        return 'Movement #'.$this->id.($this->employee ? ' of '.$this->employee->fullName() : '');
    }
}
