<?php

namespace App\Modules\Hrm\Actions;

use App\Models\User;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Hrm\Corrections\HrCorrectionRequests;
use App\Modules\Hrm\Models\Employee;
use App\Modules\Hrm\Models\HrCorrectionRequest;
use App\Modules\Hrm\Models\LeaveRequest;
use App\Modules\Hrm\Models\Movement;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * ⭐ The second person decides an HR cancellation or undo (corrections wave 3,
 * 24 Oct 2026).
 *
 * Approve: the approval and the action are ONE transaction (as in
 * LeaveController::approve). The action re-reads the record under the lock,
 * checks its fingerprint against the request, and asks its policy again for
 * the person who ASKED (who must still hold the permission). A record that
 * changed, or a rule that now refuses (the final pay was approved meanwhile),
 * rolls everything back; the request then leaves the inbox as failed, with the
 * sentence, and nothing changed.
 *
 * ⚠ Who may approve is the workflow's stage, and never the requester
 * (HrCorrectionRequest::refusesApprovalBy, Joy 19 Oct 2026).
 */
class DecideHrCorrectionRequest
{
    public function __construct(
        private readonly HrCorrectionRequests $requests,
        private readonly CancelLeaveRequest $cancelLeave,
        private readonly CancelMovement $cancelMovement,
        private readonly UndoSeparation $undoSeparation,
    ) {}

    /**
     * @return array{done: bool, message: string}
     */
    public function approve(HrCorrectionRequest $request, User $approver, ?string $note): array
    {
        $approval = $request->approvalRequest;

        if ($approval === null || ! $approval->canBeActionedBy($approver)) {
            throw new DomainException('You cannot approve this request. Somebody other than the person who asked approves it.');
        }

        try {
            return DB::transaction(function () use ($request, $approval, $approver, $note) {
                if (! $approval->approve($approver, $note)) {
                    return ['done' => false, 'message' => 'Approved. Sent to the next stage.'];
                }

                $this->execute($request, $approver);

                return ['done' => true, 'message' => "{$request->verb()} done: {$request->subject_label}."];
            });
        } catch (InvalidArgumentException|RuntimeException $e) {
            $this->requests->markFailed($request, $approver, $e->getMessage());

            return ['done' => false, 'message' => 'Nothing was changed: '.$e->getMessage()];
        }
    }

    public function reject(HrCorrectionRequest $request, User $approver, ?string $note): void
    {
        $approval = $request->approvalRequest;

        if ($approval === null || ! $approval->canBeActionedBy($approver)) {
            throw new DomainException('You cannot decide this request.');
        }

        DB::transaction(function () use ($request, $approval, $approver, $note) {
            $approval->reject($approver, $note);
            $this->requests->markRejected($request, $approver);
        });
    }

    private function execute(HrCorrectionRequest $request, User $approver): void
    {
        $requester = $request->requestedBy ?? throw new InvalidArgumentException('The person who asked no longer exists.');
        $reason = new ActionReason($request->reason_id, (string) $request->reason_text);
        $subject = $request->subject;

        match (true) {
            $request->action === HrCorrectionRequest::CANCEL_LEAVE && $subject instanceof LeaveRequest => $this->cancelLeave->handle($subject, $requester, $reason, $request, $approver),
            $request->action === HrCorrectionRequest::CANCEL_MOVEMENT && $subject instanceof Movement => $this->cancelMovement->handle($subject, $requester, $reason, $request, $approver),
            $request->action === HrCorrectionRequest::UNDO_SEPARATION && $subject instanceof Employee => $this->undoSeparation->handle($subject, $requester, $reason, $request, $approver),
            default => throw new InvalidArgumentException('The record this request is about no longer exists.'),
        };
    }
}
