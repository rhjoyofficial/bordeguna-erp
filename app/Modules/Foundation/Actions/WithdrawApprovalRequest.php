<?php

namespace App\Modules\Foundation\Actions;

use App\Models\User;
use App\Modules\Foundation\Contracts\WithdrawsFromApproval;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\CorrectionLog;
use App\Modules\Foundation\Enums\ApprovalStatus;
use App\Modules\Foundation\Models\ApprovalRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ Corrections wave 2 (B7, 24 Oct 2026): Withdraw, for every approval request
 * whose type says what withdrawing means (WithdrawsFromApproval).
 *
 *   who     the person who sent it, nobody else (a super admin included: a
 *           withdrawal is the sender changing their mind, not an approval)
 *   when    while it still waits, re-read under a lock, so a double click or an
 *           approval landing at the same moment finds it decided and is refused
 *   what    the type's own withdrawFromApproval(), then one `withdrawn` line in
 *           the corrections log (the History tab and the Corrections report)
 */
class WithdrawApprovalRequest
{
    /** Null when this person may withdraw it now; otherwise one plain sentence. */
    public static function whyNot(ApprovalRequest $request, User $by): ?string
    {
        if ($request->status !== ApprovalStatus::Pending) {
            return 'It is no longer waiting for approval.';
        }

        if ((int) $request->requested_by !== $by->id) {
            return 'Only whoever sent it for approval can withdraw it.';
        }

        $approvable = $request->approvable;

        if (! $approvable instanceof WithdrawsFromApproval) {
            return 'This kind of request cannot be withdrawn here yet. Ask the approver to send it back.';
        }

        return $approvable->whyNotWithdrawable($by, $request);
    }

    /** @throws ValidationException */
    public function handle(ApprovalRequest $request, User $by, ActionReason $reason): string
    {
        return DB::transaction(function () use ($request, $by, $reason) {
            $locked = ApprovalRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if (($why = self::whyNot($locked, $by)) !== null) {
                throw ValidationException::withMessages(['reason_text' => $why]);
            }

            /** @var WithdrawsFromApproval&Model $approvable */
            $approvable = $locked->approvable;
            $label = $approvable->withdrawLabel();

            $approvable->withdrawFromApproval($by, $locked, $reason);

            CorrectionLog::event('withdrawn', $by, $approvable, [
                'number' => $label,
                'request_id' => $locked->id,
                'reason' => $reason->display(),
            ]);

            return $label;
        });
    }
}
