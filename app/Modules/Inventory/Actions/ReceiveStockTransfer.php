<?php

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Inventory\Enums\MovementDirection;
use App\Modules\Inventory\Enums\MovementReason;
use App\Modules\Inventory\Enums\StockBatchStatus;
use App\Modules\Inventory\Enums\TransferStatus;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Support\EffectiveDate;
use App\Modules\Inventory\Support\StockValue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Confirms a dispatched transfer has physically arrived: creates the
 * destination batch(es) — one per source batch touched at dispatch, same
 * cost/expiry — and the matching in-movements, then closes out the transfer.
 */
class ReceiveStockTransfer
{
    /**
     * @return Collection<int, StockMovement>
     */
    public function handle(StockTransfer $transfer, User $performedBy, mixed $occurredAt = null): Collection
    {
        if (! $transfer->isInTransit()) {
            throw new RuntimeException("Stock transfer #{$transfer->id} has already been received.");
        }

        // ⚠ The arrival date of the RECEIVING leg. A transfer that left on the
        // 1st and landed on the 3rd is two events, not one, and the destination
        // batch did not exist at the source's date.
        $arrivedAt = EffectiveDate::resolve($occurredAt);

        return DB::transaction(function () use ($transfer, $performedBy, $arrivedAt) {
            // ⚠⚠ Re-read under a lock and checked again. The check above reads
            // the caller's copy, so a double-click passed it twice and created
            // the destination stock twice. The re-read is what holds on SQLite,
            // where the lock is a no-op; the lock is what holds on MySQL.
            $current = StockTransfer::whereKey($transfer->id)->lockForUpdate()->first();

            if ($current === null || ! $current->isInTransit()) {
                throw new RuntimeException("Stock transfer #{$transfer->id} has already been received.");
            }

            $outLegs = StockMovement::where('transfer_group_id', $transfer->transfer_group_id)
                ->where('direction', MovementDirection::Out)
                ->get();

            $movements = $outLegs->map(function (StockMovement $outLeg) use ($transfer, $performedBy, $arrivedAt) {
                $source = $outLeg->batch;

                $targetBatch = StockBatch::create([
                    'product_id' => $transfer->product_id,
                    'warehouse_id' => $transfer->target_warehouse_id,
                    'batch_number' => $source->batch_number,
                    'expiry_date' => $source->expiry_date,
                    // ⚠⚠ Traceability S10 (17 Oct 2026): the label data travels
                    // with the jars. Made-on and MRP were dropped here, so a
                    // batch moved to the shop lost its MFG and MRP and its
                    // label could not be reprinted from it.
                    'manufactured_date' => $source->manufactured_date,
                    'mrp' => $source->mrp,
                    // The batch it came from, so a trace follows it exactly.
                    'source_batch_id' => $source->id,
                    'unit_cost' => $outLeg->unit_cost,
                    'initial_quantity' => $outLeg->quantity,
                    'current_quantity' => $outLeg->quantity,
                    'status' => StockBatchStatus::Active,
                    'received_at' => $arrivedAt,
                ]);

                // ⚠ Traceability M2: stock still on the road when its batch
                // was put on hold arrives on hold too. A recall that misses
                // the van is not a recall.
                if ($source->isHeld()) {
                    app(HoldStockBatch::class)->hold(
                        $targetBatch,
                        $performedBy,
                        "Arrived on transfer while batch {$source->batch_number} was on hold: {$source->hold_reason}",
                    );
                }

                return StockMovement::create([
                    'product_id' => $transfer->product_id,
                    'warehouse_id' => $transfer->target_warehouse_id,
                    'batch_id' => $targetBatch->id,
                    'direction' => MovementDirection::In,
                    'reason' => MovementReason::Transfer,
                    'reason_id' => $transfer->reason_id,
                    'quantity' => $outLeg->quantity,
                    'unit_cost' => $outLeg->unit_cost,
                    // ⭐ 26 Oct 2026: the same value moves, to the paisa: what
                    // the departing leg took off the source batch arrives here.
                    'value_minor' => StockValue::of($outLeg)->absolute()->minor,
                    'transfer_group_id' => $transfer->transfer_group_id,
                    'occurred_at' => $arrivedAt,
                    'performed_by' => $performedBy->id,
                    'note' => $transfer->note,
                ]);
            });

            $transfer->update([
                'status' => TransferStatus::Received,
                'received_by' => $performedBy->id,
                'received_at' => now(),
            ]);

            return $movements;
        });
    }
}
