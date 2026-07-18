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
    public function handle(StockTransfer $transfer, User $performedBy): Collection
    {
        if (! $transfer->isInTransit()) {
            throw new RuntimeException("Stock transfer #{$transfer->id} has already been received.");
        }

        return DB::transaction(function () use ($transfer, $performedBy) {
            $outLegs = StockMovement::where('transfer_group_id', $transfer->transfer_group_id)
                ->where('direction', MovementDirection::Out)
                ->get();

            $movements = $outLegs->map(function (StockMovement $outLeg) use ($transfer, $performedBy) {
                $targetBatch = StockBatch::create([
                    'product_id' => $transfer->product_id,
                    'warehouse_id' => $transfer->target_warehouse_id,
                    'batch_number' => $outLeg->batch->batch_number,
                    'expiry_date' => $outLeg->batch->expiry_date,
                    'unit_cost' => $outLeg->unit_cost,
                    'initial_quantity' => $outLeg->quantity,
                    'current_quantity' => $outLeg->quantity,
                    'status' => StockBatchStatus::Active,
                ]);

                return StockMovement::create([
                    'product_id' => $transfer->product_id,
                    'warehouse_id' => $transfer->target_warehouse_id,
                    'batch_id' => $targetBatch->id,
                    'direction' => MovementDirection::In,
                    'reason' => MovementReason::Transfer,
                    'reason_id' => $transfer->reason_id,
                    'quantity' => $outLeg->quantity,
                    'unit_cost' => $outLeg->unit_cost,
                    'transfer_group_id' => $transfer->transfer_group_id,
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
