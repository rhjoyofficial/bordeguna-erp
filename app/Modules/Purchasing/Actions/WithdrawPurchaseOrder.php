<?php

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\CorrectionLog;
use App\Modules\Foundation\Corrections\DocumentAction;
use App\Modules\Purchasing\Corrections\PurchaseOrderActionPolicy;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * ⭐ Withdraw a purchase order that waits for approval (corrections wave 1,
 * 19 Oct 2026; FINAL-PLAN 2: "Waiting for approval: Withdraw").
 *
 * Before this only the approver could act on a waiting order, and the only
 * way out was a rejection, which reads as a "no" in the order's story. The
 * sender pulls it back to a draft: the approval request is handed back
 * (Returned, the engine's own send-back state, so the same request row is
 * reused when it is sent again) with the sender's reason as the note. The
 * order keeps its number; Edit sends it for approval again.
 *
 * ⚠ The policy is asked again under the lock: only the sender, only while it
 * still waits. A double click finds it already withdrawn and is refused.
 */
class WithdrawPurchaseOrder
{
    public function __construct(private readonly PurchaseOrderActionPolicy $policy) {}

    public function handle(PurchaseOrder $order, User $by, ActionReason $reason): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $by, $reason) {
            $current = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $availability = $this->policy->availability($current->load('approvalRequest'), $by, DocumentAction::Withdraw);

            if (! $availability->allowed) {
                throw new InvalidArgumentException($availability->blockers[0]->message);
            }

            $current->approvalRequest->sendBack($by, "Withdrawn by {$by->name}: {$reason->display()}");

            CorrectionLog::event('withdrawn', $by, $current, ['reason' => $reason->display()]);

            return $current->fresh('approvalRequest');
        });
    }
}
