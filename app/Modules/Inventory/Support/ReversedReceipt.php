<?php

namespace App\Modules\Inventory\Support;

use App\Modules\Inventory\Models\StockMovement;

/**
 * One batch's share of a reversal: the movement that took the goods back out,
 * and how much of the CALLER'S quantity that consumed.
 *
 * ⚠⚠ THE TWO NUMBERS ARE IN DIFFERENT UNITS AND THAT IS THE WHOLE POINT.
 * `$movement->quantity` is stock units - litres for mustard oil. `$purchaseQuantity`
 * is the unit the caller asked in, which for a purchase return is the unit the
 * supplier credits - kilograms. A return spanning two batches has to report both,
 * or the caller cannot write down what it just did.
 *
 * ⚠ Returning bare movements is what let a multi-batch return record only the
 * first one: there was nowhere to put the second batch's kilograms, so nothing
 * tried. See PurchaseReturnSpansBatchesTest.
 */
final readonly class ReversedReceipt
{
    public function __construct(
        public StockMovement $movement,
        public string $purchaseQuantity,
    ) {}
}
