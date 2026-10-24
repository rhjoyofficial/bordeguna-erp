<?php

namespace App\Modules\Hrm\Corrections;

use App\Models\User;
use App\Modules\Foundation\Actions\NotifyStageApprovers;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\CorrectionLog;
use App\Modules\Hrm\Models\HrCorrectionRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use InvalidArgumentException;

/**
 * ⭐ Corrections wave 3 (24 Oct 2026): raising and claiming an HR request for a
 * second person (HrCorrectionRequest). Called inside the action's own
 * transaction, after the policy said yes under the lock.
 *
 * ⚠ The unique pending_key is the database's last word against a double
 * click: a second request for the same record while one waits is refused,
 * whatever the screen showed.
 */
class HrCorrectionRequests
{
    public function __construct(private readonly NotifyStageApprovers $notify) {}

    public function ask(string $action, Model $subject, string $label, User $by, ActionReason $reason, string $why, string $fingerprint): HrCorrectionRequest
    {
        try {
            $request = HrCorrectionRequest::create([
                'action' => $action,
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
                'subject_label' => mb_substr($label, 0, 250),
                'reason_id' => $reason->reasonId,
                'reason_text' => $reason->text,
                'why' => mb_substr($why, 0, 250),
                'fingerprint' => $fingerprint,
                'status' => 'pending',
                'pending_key' => HrCorrectionRequest::pendingKeyFor($action, $subject),
                'requested_by' => $by->id,
            ]);
        } catch (QueryException) {
            throw new InvalidArgumentException('This is already waiting for a second person.');
        }

        $approval = $request->submitForApproval($by);
        $this->notify->handle($approval, $request->summaryLine());

        CorrectionLog::event('requested', $by, $subject, ['action' => $action, 'reason' => $reason->display(), 'why' => $why]);

        return $request;
    }

    /**
     * The approved request, locked, taken off the "waiting" list so the policy
     * asked again under the lock does not find it blocking itself. Rolled back
     * with everything else when the action then refuses.
     */
    public function claim(HrCorrectionRequest $request, Model $subject, string $fingerprint): HrCorrectionRequest
    {
        $locked = HrCorrectionRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

        if (! $locked->isPending()) {
            throw new InvalidArgumentException('This request was already decided.');
        }

        if (! hash_equals($locked->fingerprint, $fingerprint)) {
            throw new StaleHrRecord('This record changed after the request was sent. Please look again and ask again.');
        }

        $locked->update(['pending_key' => null]);

        return $locked;
    }

    public function markExecuted(HrCorrectionRequest $request, User $approver): void
    {
        $request->update(['status' => 'executed', 'decided_by' => $approver->id, 'decided_at' => now()]);
    }

    /** After a refused execution (rolled back): the request leaves the inbox with the reason. */
    public function markFailed(HrCorrectionRequest $request, User $approver, string $message): void
    {
        // ⚠ Read fresh: the rolled-back approval left the loaded copy saying
        // Approved while the database still says Pending.
        $request->refresh();
        $approval = $request->approvalRequest()->first();

        if ($approval?->isPending()) {
            $approval->reject($approver, $message);
        }

        $request->update(['status' => 'failed', 'pending_key' => null, 'failure_message' => $message, 'decided_by' => $approver->id, 'decided_at' => now()]);
    }

    public function markRejected(HrCorrectionRequest $request, User $approver): void
    {
        $request->update(['status' => 'rejected', 'pending_key' => null, 'decided_by' => $approver->id, 'decided_at' => now()]);
    }
}
