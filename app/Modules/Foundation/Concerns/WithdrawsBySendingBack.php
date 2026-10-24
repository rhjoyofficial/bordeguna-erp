<?php

namespace App\Modules\Foundation\Concerns;

use App\Models\User;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Models\ApprovalRequest;

/**
 * ⭐ Corrections follow-up (B13, 24 Oct 2026): the common shape of Withdraw
 * (WithdrawsFromApproval) for a document that has nothing of its own to undo
 * while it waits.
 *
 * ⚠ The request is SENT BACK (Returned), never rejected: nobody said no, the
 * sender changed their mind (FINAL-PLAN 3.4: a rejection "reads as a rejection
 * in the staff history"). The note names who withdrew it and why, the same
 * words the voucher and the purchase order already write, so the approval
 * history reads "Withdrawn by Karim: ..." on every type.
 *
 * A type then says what withdrawing means for IT:
 *   withdrawn()          its own status follows (back to draft, or cancelled)
 *   withdrawRefusal()    a type-specific "not now" (null = allowed)
 *   withdrawOutcome()    'draft' or 'cancelled', the sentence on the screen
 *
 * The shared rules (still waiting, only the sender, locked) are
 * WithdrawApprovalRequest's; the `withdrawn` History line is written there too.
 */
trait WithdrawsBySendingBack
{
    public function whyNotWithdrawable(User $by, ApprovalRequest $request): ?string
    {
        return $this->withdrawRefusal($by, $request);
    }

    public function withdrawFromApproval(User $by, ApprovalRequest $request, ActionReason $reason): void
    {
        $request->sendBack($by, "Withdrawn by {$by->name}: {$reason->display()}");

        $this->withdrawn($by, $reason);
    }

    /** Null when the type has nothing against a withdrawal now. */
    protected function withdrawRefusal(User $by, ApprovalRequest $request): ?string
    {
        return null;
    }

    /** The document's own state after the request was handed back. */
    protected function withdrawn(User $by, ActionReason $reason): void {}

    /** The words a cancelled-by-withdrawal record keeps as its cancel reason. */
    protected function withdrawnNote(ActionReason $reason): string
    {
        return 'Withdrawn before approval: '.$reason->display();
    }
}
