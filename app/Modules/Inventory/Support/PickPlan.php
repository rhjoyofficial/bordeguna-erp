<?php

namespace App\Modules\Inventory\Support;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\Warehouse;

/**
 * Which batches to take off the shelf, and how much of each.
 *
 * ⚠⚠ THE READ-ONLY TWIN OF `ConsumesFifoStock`, and deliberately built on the
 * SAME `consumable()` scope. That scope is the picking order the ledger will
 * actually follow: oldest expiry first with nulls last, then oldest received,
 * then id. A pick list that sorted stock its own way would send somebody to the
 * wrong shelf and the ledger would then consume a different batch, so the paper
 * and the books would disagree about which goods left the building.
 *
 * ⚠ IT IS GUIDANCE, NOT A RESERVATION. Nothing is locked and nothing is
 * decremented: the authority is still `consumeFifo` at the moment of dispatch.
 * Between printing and picking somebody may sell the same box at the counter,
 * and the printed sheet says so in as many words.
 *
 * ⚠ Expired batches are absent because the scope excludes them by DATE. A pick
 * list must never point at stock the system will refuse to issue.
 */
class PickPlan
{
    /**
     * @return array{on_hand: float, shortfall: float, batches: list<array{batch_number:?string, expiry_date:?string, take:float, available:float}>}
     */
    public static function for(Product $product, Warehouse $warehouse, float $quantity): array
    {
        $batches = StockBatch::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            ->consumable()
            ->get();

        $onHand = round((float) $batches->sum(fn (StockBatch $batch) => (float) $batch->current_quantity), 4);

        $remaining = $quantity;
        $plan = [];

        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }

            $available = (float) $batch->current_quantity;
            $take = min($remaining, $available);
            $remaining = round($remaining - $take, 4);

            $plan[] = [
                'batch_number' => $batch->batch_number,
                'expiry_date' => $batch->expiry_date?->toDateString(),
                'take' => round($take, 4),
                'available' => round($available, 4),
            ];
        }

        return [
            'on_hand' => $onHand,
            // What the warehouse cannot cover today. Printed rather than
            // hidden: the person walking to the shelf is the right person to
            // learn it, and they are about to learn it anyway.
            'shortfall' => round(max(0, $remaining), 4),
            'batches' => $plan,
        ];
    }
}
