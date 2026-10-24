<?php

namespace App\Modules\Inventory\Support;

use App\Modules\Inventory\Enums\MovementDirection;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Support\Carbon;

/**
 * ⭐ Corrections wave 1, B4 (20 Oct 2026): how low a batch's running balance
 * ever went from one movement onward, in the order the warehouse lived it
 * (occurred_at, then id).
 *
 * ⚠⚠ Why `current_quantity` is not enough. A correction that takes units out
 * of a batch on an EARLIER date (an opening count that was too high, posted on
 * the opening day) lowers every balance after that date, not just today's. A
 * batch that sold down to 2 in August and was topped up by a sales return in
 * September holds 10 today, yet taking 5 out on the opening day would leave it
 * at -3 in August. The batch-history rule (FIFO/FEFO never negative) is about
 * every point in time, so this answers with the lowest one.
 */
final class BatchHistory
{
    /** The lowest running balance of the batch from `$from` (inclusive) onward, scaled by 10,000. */
    public static function lowestScaledFrom(StockMovement $from): int
    {
        $movements = StockMovement::query()
            ->where('batch_id', $from->batch_id)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get(['id', 'direction', 'quantity', 'occurred_at']);

        $running = 0;
        $lowest = null;
        $started = false;

        foreach ($movements as $movement) {
            $scaled = (int) round((float) $movement->quantity * 10000);
            $running += $movement->direction === MovementDirection::In ? $scaled : -$scaled;

            if ($movement->id === $from->id) {
                $started = true;
            }

            if ($started) {
                $lowest = $lowest === null ? $running : min($lowest, $running);
            }
        }

        return $lowest ?? 0;
    }

    /**
     * ⭐ 24 Oct 2026 (a receipt's quantity corrected after part of it was
     * sold): would the batch's running balance stay at or above zero at every
     * point if movement `$replaced` had brought in `$scaledQuantity` at `$at`
     * instead? The new movement is placed after everything else at that same
     * moment (the order a new row would really take), which is the cautious
     * reading.
     */
    public static function staysNonNegativeWith(StockMovement $replaced, Carbon $at, int $scaledQuantity): bool
    {
        $movements = StockMovement::query()
            ->where('batch_id', $replaced->batch_id)
            ->whereKeyNot($replaced->id)
            ->get(['id', 'direction', 'quantity', 'occurred_at'])
            ->map(fn (StockMovement $m) => [
                'at' => Carbon::parse($m->occurred_at)->getTimestamp(),
                'order' => (int) $m->id,
                'delta' => ($m->direction === MovementDirection::In ? 1 : -1) * (int) round((float) $m->quantity * 10000),
            ])
            ->push(['at' => $at->getTimestamp(), 'order' => PHP_INT_MAX, 'delta' => $scaledQuantity])
            ->sortBy([['at', 'asc'], ['order', 'asc']])
            ->values();

        $running = 0;

        foreach ($movements as $movement) {
            $running += $movement['delta'];

            if ($running < 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether anything other than the opening stock itself (its receipt and
     * its corrections, all tagged `opening_stock_id`) has moved units in or
     * out of this opening batch: a sale, a transfer, a write-off, a run.
     * Value-only movements (a landed cost) are not counted here; the caller
     * compares the batch's cost separately.
     */
    public static function usedSinceOpening(StockMovement $receipt): bool
    {
        return StockMovement::query()
            ->where('batch_id', $receipt->batch_id)
            ->whereNull('opening_stock_id')
            ->where('quantity', '!=', 0)
            ->exists();
    }
}
