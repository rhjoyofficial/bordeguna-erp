<?php

namespace App\Modules\Hrm\Support;

use App\Models\User;
use App\Modules\Foundation\Models\ApprovalRequest;

/**
 * Rule #22 (approved 14 Oct 2026): the common case of MarksSelfApproval for
 * an HR money document that belongs to one employee (expense claim, loan,
 * salary revision, overtime). It is a self-approval when the approver's own
 * login is that employee: the money would be theirs.
 *
 * ⚠ Deliberately NOT "the approver also raised it". HR raises loans and
 * raises for other people every day; asking for a reason on each of those
 * would be friction with no conflict of interest behind it.
 *
 * @property int|null $employee_id
 */
trait ApprovedForOwnEmployee
{
    public function isSelfApprovalBy(User $user, ApprovalRequest $request): bool
    {
        $userId = $this->employee?->user_id;

        return $userId !== null && (int) $userId === (int) $user->id;
    }
}
