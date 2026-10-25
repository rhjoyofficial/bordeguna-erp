<?php

namespace App\Modules\Inventory\Observers;

use App\Modules\Accounting\Support\Money;
use App\Modules\Inventory\Enums\MovementDirection;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\StockLedger;
use App\Modules\Inventory\Support\StockValue;

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
    /**
     * ⭐ 26 Oct 2026: what a quantity movement MOVES, in paisa, stated on the
     * movement before it is written (`value_minor`, StockValue reads it).
     *
     *   out  its proportional share of the batch's exact value: value x
     *        quantity / quantity before, rounded to the paisa; the movement
     *        that empties the batch takes exactly what is left, so an empty
     *        batch is always worth nothing (no dust on Stock on Hand).
     *   in   quantity x unit_cost, unless the writer stated the exact value
     *        (a transfer's arriving leg, a restore, a production output).
     *
     * ⚠ A mechanism, for the same reason the quantity is derived here: every
     * path that writes a movement, including one added next year, values it
     * the same way. A revaluation (quantity 0, value_delta) and a movement
     * whose writer stated the value are left alone.
     *
     * ⚠ The batch is read fresh from the table: syncBatch rewrote it after
     * the previous movement, and the writer holds its lock.
     */
    public function creating(StockMovement $movement): void
    {
        if ($movement->value_minor !== null || $movement->value_delta !== null || $movement->batch_id === null) {
            return;
        }

        $quantity = StockValue::scaled((string) $movement->quantity);

        if ($quantity <= 0) {
            return;
        }

        $atCost = fn () => Money::ofProduct(
            number_format((float) $movement->quantity, 4, '.', ''),
            number_format((float) ($movement->unit_cost ?? 0), 4, '.', ''),
        )->minor;

        if ($movement->direction !== MovementDirection::Out) {
            $movement->value_minor = $atCost();

            return;
        }

        $batch = StockBatch::withoutGlobalScopes()->whereKey($movement->batch_id)->first(['id', 'current_quantity', 'current_value']);
        $held = $batch === null ? 0 : StockValue::scaled((string) $batch->current_quantity);

        $movement->value_minor = $held <= 0
            ? $atCost()
            : StockValue::share((int) $batch->current_value, $quantity, $held);
    }

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
