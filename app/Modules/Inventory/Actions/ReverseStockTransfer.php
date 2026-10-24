<?php

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Foundation\Corrections\ClosedPeriodPass;
use App\Modules\Inventory\Enums\MovementDirection;
use App\Modules\Inventory\Enums\MovementReason;
use App\Modules\Inventory\Models\InventoryAdjustment;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockTransfer;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * ⭐ Initial plan B24 (Joy's Q5 = A, 25 Oct 2026): a transfer that ARRIVED is
 * taken back, as if it had never been sent (the engine's Reverse,
 * StockTransferCorrector).
 *
 *   destination  the goods come back OUT of the batches the arrival made
 *                (ReverseStockReceipt on the arriving legs, at their cost)
 *   source       they go back ONTO the exact batches they left, at the cost
 *                they left at (RestoreStockIssue on the departing legs)
 *
 * No journal: a transfer posted none (Stock on Hand never moved).
 *
 * ⚠⚠ Refused while any of those goods have been used at the destination
 * (sold, moved on, counted, written off): taking them back regardless would
 * move stock that is no longer there. A destination batch counts as untouched
 * only when nothing but the arrival ever moved it, so its history can never
 * turn negative when the arrival is undone on its own date.
 *
 * ⚠ A retail seller's hand-over or return is a transfer underneath; it is
 * reversed from the seller's page (ReverseSellerTransfer), never here.
 *
 * The caller (the engine) holds the transaction and the locks.
 */
class ReverseStockTransfer
{
    public function __construct(
        private readonly ReverseStockReceipt $takeBack,
        private readonly RestoreStockIssue $restore,
    ) {}

    /** The first sentence that stops a reversal, or null. */
    public static function blocker(StockTransfer $transfer): ?string
    {
        $label = "Transfer #{$transfer->id}";

        if ($transfer->isReversed()) {
            return "{$label} was already reversed.";
        }

        if (! $transfer->isReceived()) {
            return $transfer->isInTransit()
                ? "{$label} is still on the road. Cancel it instead."
                : "{$label} was cancelled, so there is nothing to reverse.";
        }

        if ($transfer->sourceWarehouse?->isSellerStock() || $transfer->targetWarehouse?->isSellerStock()) {
            return "{$label} is a retail seller's sheet. Reverse it on the seller's page.";
        }

        if (self::liveShortage($transfer)) {
            return "{$label} arrived short and its shortage is still recorded. Reverse or withdraw that stock correction first.";
        }

        [$in, $out] = self::legs($transfer);

        if ($in->isEmpty() || $out->isEmpty()) {
            return "{$label} has no stock movements. Ask the administrator to check it.";
        }

        $target = $transfer->targetWarehouse?->name ?? 'the destination';

        foreach ($in as $arrival) {
            $batch = StockBatch::query()->find($arrival->batch_id);
            $touched = StockMovement::query()->where('batch_id', $arrival->batch_id)->whereKeyNot($arrival->id)->exists();

            if ($batch === null || $touched || bccomp((string) $batch->current_quantity, (string) $arrival->quantity, 4) !== 0) {
                return "Some of the goods from {$label} were already used at {$target}, so it cannot be reversed. Send what is left back with a new transfer.";
            }
        }

        return null;
    }

    public function handle(StockTransfer $transfer, User $by, string $reason, Carbon $on, ?ClosedPeriodPass $pass = null): StockTransfer
    {
        if (($blocked = self::blocker($transfer)) !== null) {
            throw new DomainException($blocked);
        }

        [$in, $out] = self::legs($transfer);
        $note = "Transfer #{$transfer->id} reversed: {$reason}";
        $at = $this->when($in->first(), $on);

        foreach ($in as $arrival) {
            $this->takeBack->handle([$arrival], (string) $arrival->quantity, $by, $note, MovementReason::Transfer, $at, $pass);
        }

        $this->restore->handle(
            restorations: $out->map(fn (StockMovement $leg) => ['movement' => $leg, 'quantity' => (string) $leg->quantity])->all(),
            performedBy: $by,
            note: $note,
            reason: MovementReason::Transfer,
            occurredAt: $at,
            pass: $pass,
        );

        $transfer->forceFill([
            'reversed_on' => $on->toDateString(),
            'reversed_at' => now(),
            'reversed_by' => $by->id,
            'reversal_reason' => $reason,
        ])->save();

        return $transfer->fresh();
    }

    /**
     * On the arrival's own day the movements land at the arrival's own moment
     * (a bare date would be midnight, before the batch existed); on a later
     * day, at its end.
     */
    private function when(StockMovement $movement, Carbon $on): Carbon
    {
        $original = Carbon::parse($movement->occurred_at);

        return $original->isSameDay($on) ? $original : $on->copy()->setTime(23, 59, 59);
    }

    /** A shortage stock correction still waiting or posted, not reversed. */
    private static function liveShortage(StockTransfer $transfer): bool
    {
        return InventoryAdjustment::query()
            ->where('source_type', $transfer->getMorphClass())
            ->where('source_id', $transfer->id)
            ->get()
            ->contains(fn (InventoryAdjustment $a) => ! $a->isReversed()
                && ($a->approvalRequest === null || $a->approvalRequest->isPending() || $a->approvalRequest->isComplete()));
    }

    /**
     * The arriving and the departing legs, never their own reversals.
     *
     * @return array{0: Collection<int, StockMovement>, 1: Collection<int, StockMovement>}
     */
    public static function legs(StockTransfer $transfer): array
    {
        $moves = StockMovement::query()->where('transfer_group_id', $transfer->transfer_group_id)->whereNull('reverses_movement_id')->orderBy('id')->get();

        return [
            $moves->where('direction', MovementDirection::In)->values(),
            $moves->where('direction', MovementDirection::Out)->values(),
        ];
    }
}
