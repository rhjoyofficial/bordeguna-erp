<?php

namespace App\Modules\Inventory\Observers;

use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\StockLedger;

/**
 * Keeps `stock_batches.current_quantity` derived from the ledger.
 *
 * ⚠⚠ This is the seam that makes the ledger authoritative rather than merely
 * present. Movements are the only way stock changes, so deriving here covers
 * every path that exists and every path anybody adds later - including one
 * written by somebody who never read the architecture note. "Call sync() after
 * you write movements" would have been a rule to remember; this is a mechanism.
 *
 * It also inverts the causality in the code and not just in the comments: a
 * write path now RECORDS what happened, and the quantity follows.
 *
 * ⚠ No recursion risk. This writes to `stock_batches` through the query builder,
 * never to `stock_movements`, so it cannot retrigger itself.
 */
class StockMovementObserver
{
    public function created(StockMovement $movement): void
    {
        // ⚠ batch_id is nullable. A movement that touches no batch has no cache
        // to derive, so it is skipped rather than throwing.
        $batch = $movement->batch;

        if ($batch === null) {
            return;
        }

        StockLedger::syncBatch($batch);
    }
}
