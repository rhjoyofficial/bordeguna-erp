<?php

namespace App\Modules\Hrm\Actions;

use App\Models\User;
use App\Modules\Hrm\Models\LeaveRequest;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ 27 Sep 2026 (Joy): "if authority want can give pay leave although they do
 * not have pay leave."
 *
 * LEV-07 splits a leave request into what the paid balance covers and an unpaid
 * remainder, because there is no negative paid balance. The authority may still
 * choose to PAY that remainder as a company-financed grant. This turns the
 * request's unpaid days into paid days.
 *
 * What it does and, as importantly, does NOT do:
 *  - Sets `unpaid_days` to 0, so payroll (which reads that figure for the Leave
 *    Without Pay line) stops deducting for the leave.
 *  - Records who granted it, when, and why, for the audit trail.
 *  - Draws on NO quota - the whole point is that the employee had none. No leave
 *    ledger entry is written and no balance moves; the grant is the company's,
 *    not the employee's entitlement. (27 Oct 2026: a balance that has days
 *    again by now is drawn first; see handle below.)
 *  - Does NOT reprocess the covered days. The paid/unpaid split lives only on the
 *    request; the attendance day is already "on leave" and unchanged. Touching
 *    the day would set a finalised day provisional again and needlessly re-block
 *    a pay run - the exact opposite of the intent here.
 *
 * Idempotent guard: only an applied request with unpaid days can be paid.
 */
class GrantPaidLeaveOverride
{
    public function __construct(private readonly DecideLeavePay $decide) {}

    /**
     * ⭐ 27 Oct 2026: now the "pay every day" branch of DecideLeavePay, so there
     * is one code path for the pay on a leave. On the unpaid type, or when the
     * balance is empty, that is exactly the grant described above; when the
     * balance has days again since approval, they are drawn first.
     *
     * @throws ValidationException
     */
    public function handle(LeaveRequest $request, User $grantedBy, string $reason): LeaveRequest
    {
        if (! $request->isApplied()) {
            throw ValidationException::withMessages([
                'status' => 'Only an approved leave can be paid by the authority.',
            ]);
        }

        if ((float) $request->unpaid_days <= 0.0) {
            throw ValidationException::withMessages([
                'status' => 'This leave has no unpaid days to pay.',
            ]);
        }

        return $this->decide->handle($request, $grantedBy, 0.0, $reason);
    }
}
