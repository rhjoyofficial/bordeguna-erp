<?php

namespace App\Modules\Foundation\Contracts;

use App\Models\User;
use App\Modules\Foundation\Models\ApprovalRequest;

/**
 * A document that must never be approved by certain people - its own
 * preparer, typically - whatever their role (29 Sep 2026).
 *
 * ⚠ Opt-in on purpose. Joy's standing decision of 10 Sep 2026 lets a person
 * approve their own request everywhere else; only a type that implements this
 * (a voucher above the approval limit) refuses it. ApprovalRequest checks it
 * before the stage, so it holds even for a super admin.
 */
interface RefusesSelfApproval
{
    public function refusesApprovalBy(User $user, ApprovalRequest $request): bool;
}
