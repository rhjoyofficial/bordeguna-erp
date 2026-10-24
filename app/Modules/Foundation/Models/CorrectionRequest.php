<?php

namespace App\Modules\Foundation\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\Approvable;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Contracts\RefusesSelfApproval;
use App\Modules\Foundation\Contracts\WithdrawsFromApproval;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\ApprovalRoute;
use App\Modules\Foundation\Corrections\CorrectionRequestStatus;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Foundation\Corrections\Tk;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A correction waiting for a second person or the Owner (PHASE-0-CONTRACT v2,
 * 1.4 and 1.8). Nothing touches the ledger while it waits.
 *
 * ⚠⚠ The request stores the INTENT (the replacement, the date choice), the
 * document's fingerprint, the basis and the route. When it is approved the
 * server locks and re-reads the original and re-runs every check (v2, C9). An
 * old preview token never authorises a later execution.
 *
 * ⚠ Who may NEVER approve it (checked before the stage, so it holds for a super
 * admin too): the person who asked, and, when the request needs a second person,
 * the original's maker and last editor.
 */
class CorrectionRequest extends Model implements RefusesSelfApproval, WithdrawsFromApproval
{
    use Approvable, BelongsToBusiness, LogsActivity;

    protected $fillable = [
        'business_id',
        'type',
        'document_type',
        'document_id',
        'document_number',
        'action',
        'payload',
        'dates',
        'reason_id',
        'reason_text',
        'document_fingerprint',
        'amount_basis_minor',
        'original_minor',
        'successor_minor',
        'approval_route',
        'workflow_key',
        'owner_authorised_by',
        'requested_by',
        'excluded_approvers',
        'status',
        'duplicate_ack',
        'execution_summary',
        'failure_message',
        'executed_link_id',
        'idempotency_key',
        'pending_key',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'dates' => 'array',
            'excluded_approvers' => 'array',
            'duplicate_ack' => 'array',
            'execution_summary' => 'array',
            'status' => CorrectionRequestStatus::class,
            'approval_route' => ApprovalRoute::class,
            'action' => DocumentAction::class,
            'amount_basis_minor' => 'integer',
            'original_minor' => 'integer',
            'successor_minor' => 'integer',
            'decided_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('corrections')
            ->logOnly(['status', 'failure_message', 'executed_link_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /** The workflow its route needs; an empty model answers the default (EveryApprovalReachesSomebodyTest). */
    public function approvalWorkflowKey(): string
    {
        return $this->workflow_key ?: 'correction';
    }

    public function refusesApprovalBy(User $user, ApprovalRequest $request): bool
    {
        // ⭐ Joy, 19 Oct 2026: only the requester is refused. `excluded_approvers`
        // stays on old rows as history but no longer blocks the original's maker,
        // so a request raised before this rule (e.g. Receipt #13) can be approved
        // by them too.
        return $user->id === (int) $this->requested_by || $user->id === (int) $request->requested_by;
    }

    public function document(): MorphTo
    {
        return $this->morphTo('document');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function ownerAuthorisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_authorised_by');
    }

    public function reason(): BelongsTo
    {
        return $this->belongsTo(Reason::class);
    }

    public function executedLink(): BelongsTo
    {
        return $this->belongsTo(CorrectionLink::class, 'executed_link_id');
    }

    public function isPending(): bool
    {
        return $this->status === CorrectionRequestStatus::Pending;
    }

    /**
     * ⭐ Corrections wave 2 (24 Oct 2026): the generic Withdraw on the approvals
     * inbox. The same as CorrectionRequestController::withdraw: the engine has
     * no "withdrawn" state, so the approval request is closed as rejected with
     * a note saying it was withdrawn and why (the TransitionPayrollRun
     * precedent); this request is marked Withdrawn and frees its document.
     */
    public function whyNotWithdrawable(User $by, ApprovalRequest $request): ?string
    {
        return $this->isPending() ? null : 'This request was already decided.';
    }

    public function withdrawFromApproval(User $by, ApprovalRequest $request, ActionReason $reason): void
    {
        $request->reject($by, 'Withdrawn: '.$reason->display());
        $this->forceFill(['status' => CorrectionRequestStatus::Withdrawn, 'decided_at' => now(), 'pending_key' => null])->save();
    }

    public function withdrawOutcome(): string
    {
        return 'cancelled';
    }

    public function withdrawLabel(): string
    {
        return ($this->action?->label() ?? 'Correction').' '.$this->document_number;
    }

    public function reasonDisplay(): string
    {
        $name = $this->reason?->name;

        return $name === null ? (string) $this->reason_text : "{$name}: {$this->reason_text}";
    }

    /** "Correct INC-2026-27-0007: Tk 30,000 to Tk 25,000" for the inbox card. */
    public function summary(): string
    {
        $verb = $this->action === DocumentAction::Correct ? 'Correct' : 'Reverse';
        $amounts = $this->successor_minor === null
            ? Tk::of((int) $this->original_minor)
            : Tk::of((int) $this->original_minor).' to '.Tk::of((int) $this->successor_minor);

        return "{$verb} {$this->document_number}: {$amounts}";
    }
}
