<?php

namespace App\Modules\Accounting\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\Approvable;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Contracts\MarksSelfApproval;
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
class LoanAmendment extends Model implements MarksSelfApproval
{
    use Approvable, BelongsToBusiness, LogsActivity;

    public const PENDING = 'pending';

    public const APPLIED = 'applied';

    public const REJECTED = 'rejected';

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
}
