<?php

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Foundation\Corrections\ActionReason;
use App\Modules\Foundation\Corrections\CorrectionLog;
use App\Modules\Inventory\Enums\MovementDirection;
use App\Modules\Inventory\Enums\MovementReason;
use App\Modules\Inventory\Enums\TransferStatus;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockTransfer;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ Corrections wave 1, B4 (20 Oct 2026): a transfer taken off the road
 * before it arrived (FINAL-PLAN 3.3, "Cancel (stock back to the source batch)").
 *
 * Every portion the dispatch took goes back onto the EXACT batch it left, at
 * the cost it left at (RestoreStockIssue), on the dispatch's own date: the
 * goods never stopped belonging to the source warehouse. A transfer posts no
 * journal, so none is reversed, and the in-transit add-back of the subledger
 * reconciliation stops counting it the moment its status is Cancelled.
 *
 * ⚠ Re-read under a lock: a cancel and a receive at the same gate cannot both
 * win (the receive checks the same status under the same lock).
 */
class CancelStockTransfer
{
    public function __construct(private readonly RestoreStockIssue $restore) {}

    public function handle(StockTransfer $transfer, User $by, ActionReason $why): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $by, $why) {
            $current = StockTransfer::query()->whereKey($transfer->getKey())->lockForUpdate()->first();

            if ($current === null || ! $current->isInTransit()) {
                throw new DomainException("Stock transfer #{$transfer->id} is no longer on its way, so it cannot be cancelled.");
            }

            $legs = StockMovement::query()
                ->where('transfer_group_id', $current->transfer_group_id)
                ->where('direction', MovementDirection::Out)
                ->whereNull('reverses_movement_id')
                ->orderBy('id')
                ->get();

            $written = $this->restore->handle(
                restorations: $legs->map(fn (StockMovement $leg) => ['movement' => $leg, 'quantity' => (string) $leg->quantity])->all(),
                performedBy: $by,
                note: "Transfer #{$current->id} cancelled: {$why->display()}",
                reason: MovementReason::Transfer,
                occurredAt: Carbon::parse($legs->first()->occurred_at ?? $current->dispatched_at),
            );

            StockMovement::query()->whereIn('id', $written->pluck('id'))->update([
                'transfer_group_id' => $current->transfer_group_id,
                'reason_id' => $current->reason_id,
            ]);

            $current->update([
                'status' => TransferStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $by->id,
                'cancel_reason' => $why->display(),
            ]);

            CorrectionLog::event('cancelled', $by, $current, ['reason' => $why->display(), 'quantity' => (string) $current->quantity]);

            return $current->fresh();
        });
    }
}
