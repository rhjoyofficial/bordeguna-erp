<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Enums\MovementDirection;
use App\Modules\Inventory\Enums\MovementReason;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Support\Carbon;

/**
 * THE batch revaluation primitive: a movement that changes what a batch is
 * worth and not how much of it there is (A1, migration 2026_08_02_150001).
 *
 * ⚠ Extracted on 17 Oct 2026 from ApproveInventoryAdjustment::revalue so that
 * landed cost (Phase 1) writes its value-only movements through the SAME code
 * as a stock revaluation, rather than a second hand-rolled copy that could
 * drift (the `quantity = 0` + `value_delta` + batch `unit_cost` triple has to
 * stay together, or StockValue, the subledger reconciliation and FEFO disagree).
 * ApproveInventoryAdjustment delegates here and writes exactly what it wrote
 * before.
 *
 * ⚠ `quantity = 0` is doing real work: StockLedger derives quantities by
 * summing movements, so a zero adds nothing and the quantity chain and all
 * three verification levels keep working untouched.
 *
 * ⚠ The batch's own `unit_cost` follows, so LATER withdrawals leave at the
 * revalued cost while movements already written keep the cost they went out
 * at. Historical cost of goods sold does not move, which is what accounting
 * requires.
 *
 * ⚠ `valueDelta` is stored as a positive MAGNITUDE with its direction on the
 * movement (StockValue applies the direction), STORED and never recomputed:
 * the quantity on hand changes over time, so deriving it later would give a
 * different number for the same event.
 */
class RevalueStockBatch
{
    public function handle(
        StockBatch $batch,
        MovementDirection $direction,
        string $newUnitCost,
        string $valueDelta,
        int $performedBy,
        ?string $note,
        Carbon $occurredAt,
        MovementReason $reason = MovementReason::Adjustment,
        ?int $reasonId = null,
    ): StockMovement {
        $movement = StockMovement::create([
            'product_id' => $batch->product_id,
            'warehouse_id' => $batch->warehouse_id,
            'batch_id' => $batch->id,
            // `in` raises value, `out` lowers it - the same meaning direction has
            // always had, so nothing that branches on it changes.
            'direction' => $direction,
            'reason' => $reason,
            'reason_id' => $reasonId,
            'quantity' => '0',
            'unit_cost' => $newUnitCost,
            'value_delta' => $valueDelta,
            'performed_by' => $performedBy,
            'note' => $note,
            'occurred_at' => $occurredAt,
        ]);

        StockBatch::withoutGlobalScopes()->whereKey($batch->id)->update([
            'unit_cost' => $newUnitCost,
        ]);

        return $movement;
    }
}
