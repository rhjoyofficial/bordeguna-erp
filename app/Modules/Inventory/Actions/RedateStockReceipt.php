<?php

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Foundation\Corrections\ClosedPeriodPass;
use App\Modules\Foundation\Models\AccountingPeriod;
use App\Modules\Inventory\Enums\MovementDirection;
use App\Modules\Inventory\Enums\MovementReason;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * ⭐ Corrections wave 1 (19 Oct 2026): a goods receipt was entered on the wrong
 * day. The trigger: two hilsa receipts posted 18 Oct for goods that arrived on
 * 4 Aug and 30 Sep, so a sale of 5 Aug could not be delivered from them.
 *
 * The SAME batch is kept (it is the same physical lot, and later sales already
 * point at it), and the change is RECORDED, never rewritten:
 *
 *   1. a new `in` movement into that batch on the true date, at the receipt
 *      movement's own quantity and cost (the corrected receipt points at it);
 *   2. an `out` movement on the receipt's original date that `reverses_movement_id`
 *      the old receipt movement, at the same quantity and cost;
 *   3. the batch's `received_at` moves to the true date, so FIFO and the "no
 *      withdrawal before the stock existed" rule read the true day.
 *
 * The batch quantity and value never change (1 and 2 cancel), and the stock
 * card shows both the wrong day and its correction. The ledger side is the
 * caller's (reverse the receipt journal, post the corrected receipt's journal).
 *
 * ⚠⚠ Refused when the batch was used BEFORE the new date: a later receipt date
 * would make those withdrawals happen before the stock existed (the running
 * balance would go negative partway through its own history). Moving a receipt
 * EARLIER never breaks anything.
 *
 * ⚠ Written in the order in, then out, so the cached quantity never dips below
 * zero between the two (StockMovementObserver re-derives it after each).
 */
class RedateStockReceipt
{
    /**
     * @param  array{batch_number?: ?string, manufactured_date?: ?string, expiry_date?: ?string}  $label  the batch's label data, when the correction fixes it too
     */
    public function handle(
        StockMovement $receipt,
        Carbon $newDate,
        User $by,
        string $note,
        ?ClosedPeriodPass $pass = null,
        array $label = [],
    ): StockMovement {
        if ($receipt->direction !== MovementDirection::In || $receipt->batch_id === null) {
            throw new LogicException("Movement #{$receipt->id} is not a receipt into a batch.");
        }

        $newDate = $newDate->copy()->startOfDay();
        $originalDate = Carbon::parse($receipt->occurred_at);

        if ($newDate->isFuture()) {
            throw new InvalidArgumentException('A receipt cannot be dated in the future.');
        }

        // ⚠ Both days are written to: the true day (the new `in`) and the
        // original day (the `out` that undoes it). The pass, when there is
        // one, is the Owner's one-document exception for exactly those days.
        AccountingPeriod::assertOpenFor($newDate, $pass);
        AccountingPeriod::assertOpenFor($originalDate, $pass);

        return DB::transaction(function () use ($receipt, $newDate, $originalDate, $by, $note, $label) {
            $batch = StockBatch::query()->whereKey($receipt->batch_id)->lockForUpdate()->firstOrFail();

            if ($newDate->greaterThan($originalDate)) {
                $usedEarlier = StockMovement::query()
                    ->where('batch_id', $batch->id)
                    ->whereKeyNot($receipt->id)
                    ->where('direction', MovementDirection::Out->value)
                    ->where('quantity', '>', 0)
                    ->where('occurred_at', '<', $newDate)
                    ->orderBy('occurred_at')
                    ->first();

                if ($usedEarlier !== null) {
                    $on = Carbon::parse($usedEarlier->occurred_at)->format('j M Y');
                    $name = $batch->batch_number ?? "#{$batch->id}";

                    throw new InvalidArgumentException("Stock from batch {$name} was used on {$on}, before {$newDate->format('j M Y')}, so the receipt cannot move to that later day.");
                }
            }

            $in = StockMovement::create([
                'product_id' => $receipt->product_id,
                'warehouse_id' => $receipt->warehouse_id,
                'batch_id' => $batch->id,
                'direction' => MovementDirection::In,
                'reason' => MovementReason::Receipt,
                'quantity' => $receipt->quantity,
                'unit_cost' => $receipt->unit_cost,
                'performed_by' => $by->id,
                'note' => $note,
                'occurred_at' => $newDate,
            ]);

            StockMovement::create([
                'product_id' => $receipt->product_id,
                'warehouse_id' => $receipt->warehouse_id,
                'batch_id' => $batch->id,
                'direction' => MovementDirection::Out,
                'reason' => MovementReason::ReceiptCorrection,
                'quantity' => $receipt->quantity,
                'unit_cost' => $receipt->unit_cost,
                'reverses_movement_id' => $receipt->id,
                'performed_by' => $by->id,
                'note' => $note,
                'occurred_at' => $originalDate,
            ]);

            $batch->forceFill(array_merge(
                ['received_at' => $newDate],
                array_intersect_key($label, array_flip(['batch_number', 'manufactured_date', 'expiry_date'])),
            ))->save();

            return $in;
        });
    }
}
