<?php

namespace App\Modules\Hrm\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\Approvable;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Concerns\WithdrawsBySendingBack;
use App\Modules\Foundation\Contracts\MarksSelfApproval;
use App\Modules\Foundation\Contracts\WithdrawsFromApproval;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Hrm\Support\ApprovedForOwnEmployee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * One employee's share of a bonus event (ADJ-05).
 *
 * Approvable in its own right rather than approving the event wholesale, so a
 * single employee can be turned down — a resignation served, a disciplinary
 * matter — without unpicking a festival bonus for two hundred people.
 *
 * The amount is resolved when the award is made and stored, never recomputed:
 * an increment three months later must not quietly restate what a past bonus
 * was worth.
 */
class BonusAward extends Model implements MarksSelfApproval, WithdrawsFromApproval
{
    // Rule #22 (14 Oct 2026): approving your own bonus needs a reason and is marked.
    use Approvable, ApprovedForOwnEmployee, BelongsToBusiness, HasFactory, LogsActivity, WithdrawsBySendingBack;

    protected $fillable = [
        'business_id',
        'bonus_event_id',
        'employee_id',
        'amount',
        'basis_note',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'cancelled_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['bonus_event_id', 'employee_id', 'amount', 'basis_note'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(BonusEvent::class, 'bonus_event_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function isApproved(): bool
    {
        return $this->approvalRequest?->status === ApprovalStatus::Approved;
    }

    /**
     * Only an approved award reaches a payslip.
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->whereHas('approvalRequest', fn ($q) => $q->where('status', ApprovalStatus::Approved))
            ->whereNull($query->getModel()->qualifyColumn('cancelled_at'));
    }

    /** ⭐ B24 (25 Oct 2026): cancelled after approval (CancelPayrollInput); payroll skips it. */
    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function summaryLine(): string
    {
        return sprintf(
            '%s — %s bonus of %s',
            $this->employee?->fullName(),
            $this->event?->name,
            number_format((float) $this->amount, 2),
        );
    }

    // Withdraw (B13, 24 Oct 2026): the sender pulls it back while it waits.

    public function withdrawOutcome(): string
    {
        return 'cancelled';
    }

    public function withdrawLabel(): string
    {
        return 'Bonus award #'.$this->id.($this->employee ? ' for '.$this->employee->fullName() : '');
    }
}
