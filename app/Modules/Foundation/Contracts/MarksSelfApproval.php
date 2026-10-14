<?php

namespace App\Modules\Foundation\Contracts;

use App\Models\User;
use App\Modules\Foundation\Models\ApprovalRequest;

/**
 * Rule #22 (approved 14 Oct 2026): a document whose approval can put money in
 * the approver's own pocket. Self-approval stays ALLOWED (Joy's decision of
 * 10 Sep 2026, kept after the ChatGPT review advised Owner-only), but
 * ApprovalRequest::approve() then requires a short reason and records the
 * decision as self-approved, so the history shows it.
 *
 * ⚠ The opposite of RefusesSelfApproval, which blocks. This one never blocks.
 */
interface MarksSelfApproval
{
    /** Whether approving this would be approving the approver's own money. */
    public function isSelfApprovalBy(User $user, ApprovalRequest $request): bool;
}
