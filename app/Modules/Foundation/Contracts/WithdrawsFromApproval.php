<?php

namespace App\Modules\Foundation\Contracts;

use App\Models\User;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Models\ApprovalRequest;

/**
 * ⭐ Corrections wave 2 (B7, 24 Oct 2026; FINAL-PLAN 3.5 "Approval request:
 * Withdraw, while waiting, by the sender"). The approval engine had no
 * withdraw: only the approver could act, and a rejection reads as a "no" in
 * the record's story.
 *
 * A type opts in by saying what withdrawing means for IT (a voucher goes back
 * to a draft, a set-off is closed as withdrawn). The generic action checks the
 * shared rules first: the request still waits, and the person is its sender.
 *
 * ⚠ Opt-in on purpose. Handing the request back without the document's own
 * status following it leaves the document stuck as "waiting" with nobody able
 * to act on it; a type that has not said what withdraw means is greyed with a
 * sentence, never guessed.
 */
interface WithdrawsFromApproval
{
    /** Null when the document may be withdrawn now; otherwise one plain sentence. */
    public function whyNotWithdrawable(User $by, ApprovalRequest $request): ?string;

    /**
     * Inside the transaction, with the request locked and still pending: hand
     * the request back and move the document to its own "not sent" state.
     */
    public function withdrawFromApproval(User $by, ApprovalRequest $request, ActionReason $reason): void;

    /** "PV draft of Tk 5,000", for the flash and the history. */
    public function withdrawLabel(): string;
}
