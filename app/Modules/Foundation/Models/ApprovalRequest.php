<?php

namespace App\Modules\Foundation\Models;

use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
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
        $this->recordDecision($reviewedBy, ApprovalStatus::Rejected, $reviewNote);

        $this->update([
            'status' => ApprovalStatus::Rejected,
            'reviewed_by' => $reviewedBy->id,
            'reviewed_at' => now(),
            'review_note' => $reviewNote,
        ]);
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
