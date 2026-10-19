<?php

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\CorrectionLog;
use App\Modules\Inventory\Models\InventoryAdjustment;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ Corrections wave 1, B4 (20 Oct 2026): the person who raised a stock
 * correction pulls it back while it waits (FINAL-PLAN 3.3, "Withdraw").
 *
 * ⚠ Back to a draft, not a rejection: nobody turned it down. The approval row
 * goes to Returned (the engine's "handed back" state, which only the raiser
 * can clear by sending it again), the record and its number stay, and the
 * reason is kept both on the approval history and in the Corrections report.
 * Nothing moved while it waited, so nothing is undone.
 */
class WithdrawInventoryAdjustment
{
    public function handle(InventoryAdjustment $adjustment, User $by, ActionReason $why): InventoryAdjustment
    {
        return DB::transaction(function () use ($adjustment, $by, $why) {
            $current = InventoryAdjustment::query()->whereKey($adjustment->getKey())->lockForUpdate()->firstOrFail();

            if ((int) $current->created_by !== (int) $by->id) {
                throw new DomainException('Only the person who raised this correction can withdraw it.');
            }

            if (! $current->approvalRequest?->isPending()) {
                throw new DomainException('This correction is not waiting for approval, so there is nothing to withdraw.');
            }

            $current->approvalRequest->sendBack($by, 'Withdrawn: '.$why->display());

            CorrectionLog::event('withdrawn', $by, $current, ['reason' => $why->display()]);

            return $current->fresh(['approvalRequest']);
        });
    }
}
