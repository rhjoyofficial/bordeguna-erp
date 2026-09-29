<?php

namespace App\Modules\Foundation\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Contracts\RefusesSelfApproval;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Foundation\Exceptions\ApprovalAlreadyDecidedException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * The generic approval-queue record shared by every "requires approval"
 * document type across modules. It is also a **multi-stage workflow instance**:
 * `status` is the OVERALL result (pending/approved/rejected) that every reader
 * relies on, while `workflow_id` + `current_stage` drive it through an ordered
 * ApprovalWorkflow, one ApprovalDecision recorded per stage.
 *
 * Backward compatibility is deliberate and load-bearing: `submitForApproval`,
 * `approve`, `reject`, and `isPending` keep their exact contracts, and a
 * request with **no** configured workflow (workflow_id null) behaves as a
 * single implicit stage — so the existing single-stage Inventory/Purchasing
 * approvals, and their tests, work unchanged.
 */
class ApprovalRequest extends Model
{
    use BelongsToBusiness, HasFactory, LogsActivity;

    protected $fillable = [
        'business_id',
        'workflow_id',
        'current_stage',
        'status',
        'requested_by',
        'reviewed_by',
        'reviewed_at',
        'review_note',
    ];

    protected function casts(): array
    {
        return [
            'status' => ApprovalStatus::class,
            'current_stage' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'current_stage', 'review_note'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(ApprovalWorkflow::class, 'workflow_id');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(ApprovalDecision::class)->orderBy('id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isPending(): bool
    {
        return $this->status === ApprovalStatus::Pending;
    }

    // --- Workflow position (requirement: know current stage / remaining / done) ---

    /**
     * Total stages in the driving workflow. No workflow → a single implicit
     * stage (the pre-workflow behavior).
     */
    public function totalStages(): int
    {
        return $this->workflow?->stageCount() ?: 1;
    }

    /**
     * The stage the request is currently awaiting a decision on, or null when
     * there is no configured workflow (implicit single stage).
     */
    public function currentStage(): ?ApprovalWorkflowStage
    {
        return $this->workflow?->stageAt($this->current_stage);
    }

    /**
     * Stages still awaiting a decision (the current one counts). 0 once the
     * request is approved or rejected.
     */
    public function remainingStages(): int
    {
        if (! $this->isPending()) {
            return 0;
        }

        return max(0, $this->totalStages() - ($this->current_stage - 1));
    }

    public function isComplete(): bool
    {
        return $this->status === ApprovalStatus::Approved;
    }

    /**
     * Whether this user may act on the stage the request is currently on. Used
     * by the generic (workflow-driven) approve/reject path; the legacy
     * consumers keep their own explicit permission `authorize()` calls.
     */
    public function canBeActionedBy(User $user): bool
    {
        if (! $this->isPending()) {
            return false;
        }

        // Checked before the stage, so it holds even for a super admin. Only a
        // type that opts in (RefusesSelfApproval) is ever loaded for it.
        $class = Relation::getMorphedModel($this->approvable_type) ?? $this->approvable_type;

        if (is_string($class) && is_subclass_of($class, RefusesSelfApproval::class)
            && $this->approvable?->refusesApprovalBy($user, $this)) {
            return false;
        }

        return $this->currentStage()?->allows($user) ?? false;
    }

    // --- Decisions -------------------------------------------------------------

    /**
     * Approve the current stage. If it was the final stage the whole request is
     * Approved (and reviewed_by/at are set from this decision — unchanged
     * behavior for a single-stage request); otherwise it advances to the next
     * stage and stays Pending. Returns whether the request is now complete.
     */
    public function approve(User $reviewedBy, ?string $note = null): bool
    {
        $this->assertStillPending();

        $this->recordDecision($reviewedBy, ApprovalStatus::Approved, $note);

        if ($this->current_stage >= $this->totalStages()) {
            $this->update([
                'status' => ApprovalStatus::Approved,
                'reviewed_by' => $reviewedBy->id,
                'reviewed_at' => now(),
                'review_note' => $note ?? $this->review_note,
            ]);

            return true;
        }

        $this->update(['current_stage' => $this->current_stage + 1]);

        return false;
    }

    /**
     * Reject the request at the current stage. Any stage's rejection ends the
     * whole request immediately (unchanged single-stage behavior).
     */
    public function reject(User $reviewedBy, ?string $reviewNote = null): void
    {
        $this->assertStillPending();

        $this->recordDecision($reviewedBy, ApprovalStatus::Rejected, $reviewNote);

        $this->update([
            'status' => ApprovalStatus::Rejected,
            'reviewed_by' => $reviewedBy->id,
            'reviewed_at' => now(),
            'review_note' => $reviewNote,
        ]);
    }

    /**
     * Hand the request back to whoever raised it, with a note saying why.
     *
     * ⚠⚠ THIS IS NOT A REJECTION AND MUST NEVER BECOME ONE. Rejecting a claim
     * because one figure is wrong forces the employee to type the whole thing
     * again from nothing, and the record of what they originally asked for is
     * gone. Sending it back keeps the document, keeps its number, keeps every
     * decision already recorded against it, and asks for a correction.
     *
     * ⚠ The note is REQUIRED by the caller, not by this method's signature -
     * "sent back" with no words is a decision the employee cannot act on. The
     * controller validates it; this records whatever it is given.
     *
     * ⚠ The stage goes back to 1. A three-stage claim returned by the second
     * approver has to be looked at again by the first, because the first
     * approved a document that no longer exists in that form.
     */
    public function sendBack(User $reviewedBy, ?string $note = null): void
    {
        $this->assertStillPending();

        $this->recordDecision($reviewedBy, ApprovalStatus::Returned, $note);

        $this->update([
            'status' => ApprovalStatus::Returned,
            'current_stage' => 1,
            'reviewed_by' => $reviewedBy->id,
            'reviewed_at' => now(),
            'review_note' => $note,
        ]);
    }

    /**
     * The requester has fixed it and is asking again.
     *
     * ⚠⚠ THE SAME REQUEST ROW IS REUSED, DELIBERATELY. `approvalRequest` is a
     * morphOne: a second row for the same document would be invisible to every
     * reader in the system, which would silently strand the resubmission. The
     * decision history hangs off this row, so reusing it is also what keeps
     * "sent back on Tuesday, resubmitted on Wednesday" readable in one place.
     */
    public function resubmit(): void
    {
        if ($this->status !== ApprovalStatus::Returned) {
            throw ApprovalAlreadyDecidedException::for($this);
        }

        $this->update([
            'status' => ApprovalStatus::Pending,
            'current_stage' => 1,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'review_note' => null,
        ]);
    }

    /**
     * Refuse a second decision on a request somebody has already settled.
     *
     * ⚠⚠ THIS IS THE ONLY THING STANDING BETWEEN TWO APPROVERS AND CORRUPTED
     * DATA. Both can hold the same pending request on screen. Without this,
     * one approving and the other rejecting wrote a second decision row and
     * overwrote `status`, while the underlying leave, loan or movement stayed
     * in whatever state the FIRST decision applied. Nothing errored, which is
     * why it went unnoticed.
     *
     * ⚠ Re-read from the database rather than trusting `$this->status`. The
     * instance in memory was loaded when the page was rendered, possibly
     * minutes ago, and it is exactly the stale copy the race runs through.
     *
     * ⚠⚠ `lockForUpdate` IS DELIBERATE AND IS NOT THE GUARD. It serialises two
     * simultaneous requests on MySQL so the loser reads the winner's committed
     * status. It is a NO-OP on SQLite, so the tests would still pass with it
     * removed - the status comparison below is what actually refuses, and it is
     * correct on both. Recorded because this project has been bitten by a
     * `lockForUpdate` that silently did nothing before.
     */
    private function assertStillPending(): void
    {
        $current = static::query()
            ->withoutGlobalScopes()
            ->whereKey($this->getKey())
            ->lockForUpdate()
            ->first();

        if ($current === null || $current->status !== ApprovalStatus::Pending) {
            throw ApprovalAlreadyDecidedException::for($current ?? $this);
        }

        // The winner may have advanced the stage rather than finishing the
        // request. Approving "the current stage" from a page that showed an
        // earlier one is a decision nobody made.
        if ($current->current_stage !== $this->current_stage) {
            throw ApprovalAlreadyDecidedException::for($current);
        }
    }

    private function recordDecision(User $decidedBy, ApprovalStatus $decision, ?string $note): void
    {
        $this->decisions()->create([
            'stage_sequence' => $this->current_stage,
            'stage_name' => $this->currentStage()?->name ?? 'Approval',
            'decided_by' => $decidedBy->id,
            'decision' => $decision,
            'note' => $note,
            'decided_at' => now(),
        ]);
    }
}
