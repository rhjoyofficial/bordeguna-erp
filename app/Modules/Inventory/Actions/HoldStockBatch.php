<?php

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockBatchHold;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Put a batch on hold, or release it (Traceability M2, 17 Oct 2026).
 *
 * A held batch is skipped by FEFO everywhere (`StockBatch::consumable()`), so
 * a suspect jar cannot leave by the counter, a challan, a transfer or a
 * production run while it is checked. Nothing moves and nothing posts: the
 * batch stays on the book at its cost. Each step needs a reason and is kept
 * in `stock_batch_holds`.
 */
class HoldStockBatch
{
    public function hold(StockBatch $batch, User $by, string $reason): StockBatch
    {
        return $this->change($batch, $by, $reason, StockBatchHold::HOLD);
    }

    public function release(StockBatch $batch, User $by, string $reason): StockBatch
    {
        return $this->change($batch, $by, $reason, StockBatchHold::RELEASE);
    }

    private function change(StockBatch $batch, User $by, string $reason, string $action): StockBatch
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException('Write the reason.');
        }

        return DB::transaction(function () use ($batch, $by, $reason, $action) {
            // ⚠ Re-read under a lock: two people pressing Hold at once must
            // leave one hold and one history row, not two.
            $current = StockBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            $label = $current->batch_number ?? "#{$current->id}";

            if ($action === StockBatchHold::HOLD && $current->isHeld()) {
                throw new DomainException("Batch {$label} is already on hold.");
            }

            if ($action === StockBatchHold::RELEASE && ! $current->isHeld()) {
                throw new DomainException("Batch {$label} is not on hold.");
            }

            $current->update($action === StockBatchHold::HOLD
                ? ['held_at' => now(), 'held_by' => $by->id, 'hold_reason' => $reason]
                : ['held_at' => null, 'held_by' => null, 'hold_reason' => null]);

            StockBatchHold::create([
                'business_id' => $current->business_id,
                'stock_batch_id' => $current->id,
                'action' => $action,
                'reason' => $reason,
                'user_id' => $by->id,
            ]);

            return $current;
        });
    }
}
