<?php

namespace App\Modules\Accounting\Models;

use App\Models\User;
use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Concerns\Approvable;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Concerns\HasAttachments;
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
 * ⚠⚠ TWO DIFFERENT THINGS, NEVER ONE (ChatGPT review, point 9):
 *
 *  - PRINCIPAL WRITE-OFF (a loan given only): money lent that will not come
 *    back. Dr 6406 Bad Debts Written Off / Cr the principal head.
 *  - INTEREST WAIVER: interest the lender gives up. Interest already accrued
 *    is reversed (given: Dr 4920 / Cr 11753; taken: Dr 2127 / Cr 6478);
 *    interest not yet accrued is simply forgone on the schedule, no journal.
 *
 * Both need the Owner's approval, a reason and evidence; approving posts it.
 */
class LoanWriteOff extends Model implements MarksSelfApproval, WithdrawsFromApproval
{
    use Approvable, BelongsToBusiness, HasAttachments, LogsActivity, WithdrawsBySendingBack;

    public const PRINCIPAL = 'principal_write_off';

    public const INTEREST = 'interest_waiver';

    public const PENDING = 'pending';

    public const POSTED = 'posted';

    public const REJECTED = 'rejected';

    /** B13 (24 Oct 2026): pulled back by its sender before anyone decided. */
    public const WITHDRAWN = 'withdrawn';

    public const EVIDENCE_COLLECTION = 'evidence';

    protected $fillable = ['business_id', 'loan_contract_id', 'kind', 'amount_minor', 'forgo_minor', 'effective_date', 'reason', 'status', 'created_by', 'posted_at'];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'forgo_minor' => 'integer',
            'effective_date' => 'date:Y-m-d',
            'posted_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['kind', 'amount_minor', 'forgo_minor', 'effective_date', 'reason', 'status'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function approvalWorkflowKey(): string
    {
        return 'loan_write_off';
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

    public function isPrincipal(): bool
    {
        return $this->kind === self::PRINCIPAL;
    }

    public function amount(): Money
    {
        return Money::ofMinor((int) $this->amount_minor);
    }

    public function label(): string
    {
        return $this->isPrincipal() ? 'Principal write-off' : 'Interest waiver';
    }

    // Withdraw (B13, 24 Oct 2026): the sender pulls it back while it waits.

    protected function withdrawRefusal(User $by, ApprovalRequest $request): ?string
    {
        return $this->status === self::PENDING ? null : 'This write-off is not waiting for approval.';
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
        return $this->label().' #'.$this->id.($this->loan ? ' on '.$this->loan->loan_number : '');
    }
}
