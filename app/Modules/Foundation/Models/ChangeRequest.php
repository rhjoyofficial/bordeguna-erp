<?php

namespace App\Modules\Foundation\Models;

use App\Models\User;
use App\Modules\Foundation\Concerns\Approvable;
use App\Modules\Foundation\Concerns\BelongsToBusiness;
use App\Modules\Foundation\Concerns\WithdrawsBySendingBack;
use App\Modules\Foundation\Contracts\HandlesChangeRequest;
use App\Modules\Foundation\Contracts\WithdrawsFromApproval;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A generic, approval-gated request to change some record. Subject is
 * polymorphic and the applier is pluggable (HandlesChangeRequest), so this one
 * model serves Employee, Supplier, Product, Business Settings, bank accounts,
 * and any future consumer without special cases. Approval rides the shared
 * multi-stage workflow engine (Approvable): the workflow is chosen per request
 * via workflow_key, so the same subject type can route different fields through
 * different approval chains (an employee's phone → HR; their bank → HR+Finance).
 */
class ChangeRequest extends Model implements WithdrawsFromApproval
{
    use Approvable, BelongsToBusiness, HasFactory, WithdrawsBySendingBack;

    protected $fillable = [
        'business_id',
        'subject_type',
        'subject_id',
        'workflow_key',
        'proposed_changes',
        'requested_by',
    ];

    protected function casts(): array
    {
        return ['proposed_changes' => 'array'];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** The workflow engine resolves the driving workflow from this key. */
    public function approvalWorkflowKey(): string
    {
        return $this->workflow_key;
    }

    /**
     * field-label => value for the proposed changes, delegated to the subject so
     * display stays correct for any subject type.
     *
     * @return array<string, string>
     */
    public function describedChanges(): array
    {
        $subject = $this->subject;

        return $subject instanceof HandlesChangeRequest
            ? $subject->describeChangeRequest($this->proposed_changes)
            : array_map(fn ($value) => (string) $value, $this->proposed_changes);
    }

    /** One-line summary for the inbox / notifications. */
    public function summaryLine(): string
    {
        $subject = $this->subject;
        $label = $subject instanceof HandlesChangeRequest
            ? $subject->changeRequestSubjectLabel()
            : '#'.$this->subject_id;

        return $label.' — '.implode(', ', array_keys($this->describedChanges()));
    }

    // Withdraw (B13, 24 Oct 2026): the sender pulls it back while it waits.

    public function withdrawOutcome(): string
    {
        return 'cancelled';
    }

    public function withdrawLabel(): string
    {
        return 'Change request #'.$this->id;
    }
}
