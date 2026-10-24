<?php

namespace App\Modules\Accounting\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\Approvable;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Concerns\WithdrawsBySendingBack;
use App\Modules\Foundation\Contracts\MarksSelfApproval;
use App\Modules\Foundation\Contracts\WithdrawsFromApproval;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Models\ApprovalRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A restructure: new terms for the principal still owed, from a date, asked
 * for with a reason and applied only once the Owner approves.
 *
 * ⚠ Applying it ADDS a terms version and a schedule; the old ones stay as
 * history, and accruals already posted are never rewritten (a re-run of the
 * month posts a controlled adjustment instead).
 */
class LoanAmendment extends Model implements MarksSelfApproval, WithdrawsFromApproval
{
    use Approvable, BelongsToBusiness, LogsActivity, WithdrawsBySendingBack;

    public const PENDING = 'pending';

    public const APPLIED = 'applied';

    public const REJECTED = 'rejected';

    /** B13 (24 Oct 2026): pulled back by its sender before anyone decided. */
    public const WITHDRAWN = 'withdrawn';

    protected $fillable = ['business_id', 'loan_contract_id', 'status', 'from_version', 'to_version', 'effective_from', 'terms', 'reason', 'created_by', 'applied_at'];

    protected function casts(): array
    {
        return [
            'from_version' => 'integer',
            'to_version' => 'integer',
            'effective_from' => 'date:Y-m-d',
            'terms' => 'array',
            'applied_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['status', 'effective_from', 'terms', 'reason', 'to_version'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function approvalWorkflowKey(): string
    {
        return 'loan_amendment';
    }

    public function isSelfApprovalBy(User $user, ApprovalRequest $request): bool
    {
        return $user->id === (int) $this->created_by || $user->id === (int) $request->requested_by;
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(LoanContract::class, 'loan_contract_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Withdraw (B13, 24 Oct 2026): the sender pulls it back while it waits.

    protected function withdrawRefusal(User $by, ApprovalRequest $request): ?string
    {
        return $this->status === self::PENDING ? null : 'This change of terms is not waiting for approval.';
    }

    protected function withdrawn(User $by, ActionReason $reason): void
    {
        $this->forceFill(['status' => self::WITHDRAWN])->save();
    }

    public function withdrawOutcome(): string
    {
        return 'cancelled';
    }

    public function withdrawLabel(): string
    {
        return 'Change of terms #'.$this->id.($this->loan ? ' on '.$this->loan->loan_number : '');
    }
}
