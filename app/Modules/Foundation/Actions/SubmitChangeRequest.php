<?php

namespace App\Modules\Foundation\Actions;

use App\Models\User;
use App\Modules\Foundation\Contracts\HandlesChangeRequest;
use App\Modules\Foundation\Models\ChangeRequest;
use Illuminate\Database\Eloquent\Model;

/**
 * Opens a change request against any subject and routes it into the multi-stage
 * approval engine, notifying the first stage's approvers. Generic: the caller
 * (a module) supplies the subject, the already-validated changes, and the
 * workflow key its policy chose — this action knows nothing about employees or
 * suppliers.
 */
class SubmitChangeRequest
{
    public function __construct(private readonly NotifyStageApprovers $notifyStageApprovers) {}

    /**
     * @param  Model&HandlesChangeRequest  $subject
     * @param  array<string, mixed>  $changes  field => new value (already validated)
     */
    public function handle(Model $subject, array $changes, string $workflowKey, User $requestedBy, int $businessId): ChangeRequest
    {
        $changeRequest = ChangeRequest::create([
            'business_id' => $businessId,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'workflow_key' => $workflowKey,
            'proposed_changes' => $changes,
            'requested_by' => $requestedBy->id,
        ]);

        $changeRequest->submitForApproval($requestedBy);

        $this->notifyStageApprovers->handle($changeRequest->approvalRequest, $changeRequest->summaryLine());

        return $changeRequest;
    }
}
