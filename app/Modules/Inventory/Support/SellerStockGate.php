<?php

namespace App\Modules\Inventory\Support;

use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ Initial plan B21 (24 Oct 2026, Joy's Q3 = A): a retail seller's stock is
 * moved and sold ONLY by the seller sheets (hand-over, reported sale, return
 * of unsold goods). Everything else that would take goods out of a warehouse
 * (a challan, so the counter, orders and staff purchases too; a warehouse
 * transfer) asks this gate first and is refused with one sentence.
 *
 * ⚠ Why at the action and not only in the pickers: the pickers leave seller
 * stocks out (Warehouse::operational), but a posted form or an import names a
 * warehouse by id, and FEFO would happily sell Rakib's jars to a walk-in at
 * the shop counter. The seller actions open the gate around their own calls
 * (`through`), always closed again in `finally`.
 */
final class SellerStockGate
{
    private static int $open = 0;

    /**
     * Run `$work` with seller stocks usable. Only the seller actions call this.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public static function through(callable $work): mixed
    {
        self::$open++;

        try {
            return $work();
        } finally {
            self::$open--;
        }
    }

    public static function assertMayUse(Warehouse $warehouse, string $field = 'warehouse_id'): void
    {
        if ($warehouse->isSellerStock() && self::$open === 0) {
            throw ValidationException::withMessages([
                $field => "{$warehouse->name} is a retail seller's stock. Its goods move only by a hand-over, the seller's reported sales or a return of unsold goods (Sales, Retail sellers).",
            ]);
        }
    }
}
