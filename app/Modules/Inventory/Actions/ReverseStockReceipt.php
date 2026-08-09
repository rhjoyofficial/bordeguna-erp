<?php

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Inventory\Enums\MovementDirection;
use App\Modules\Inventory\Enums\MovementReason;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\EffectiveDate;
use App\Modules\Inventory\Support\PurchaseUnitConversion;
use App\Modules\Inventory\Support\ReversedReceipt;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Sends stock back out through the exact cost layer it came in on (I1).
 *
 * ⚠⚠ Why this exists at all, rather than calling `RecordStockOut`.
 *
 * A purchase return is NOT a withdrawal. A withdrawal asks "which layer should
 * bear this cost?" and FIFO is the right answer. A return already knows: the
 * goods going back to the supplier are the goods that supplier delivered, and
 * the credit note is at their price. Sending it through FIFO relieved the oldest
 * batch in the warehouse instead, so inventory was reduced at one cost while the
 * supplier credited another, the batch that physically left still showed full,
 * and nothing anywhere compared the two.
 *
 * The link was never missing. `goods_receipt_lines.stock_movement_id` records
 * precisely which receipt created which batch.
 *
 * ⚠ Honours the §10 auditability rule: the movement written here carries
 * `reverses_movement_id`, so the ledger states what it undoes rather than merely
 * that something left. Nothing edits a quantity without a movement to explain it.
 */
class ReverseStockReceipt
{
    /**
     * Reverses `$quantity` across the given receipt movements, oldest receipt
     * first, taking whatever each still holds.
     *
     * All-or-nothing. If the deliveries no longer hold enough between them, this
     * throws and the caller's transaction rolls back - per C-INV-2, an
     * impossible movement is refused rather than fudged. You cannot send back
     * goods you have already sold, and the old behaviour of quietly eating
     * somebody else's cost layer is exactly the drift being fixed.
     *
     * @param  Collection<int, StockMovement>|array<int, StockMovement>  $receipts
     * @return Collection<int, ReversedReceipt> one entry per batch touched, each
     *                                          carrying the movement AND the share
     *                                          of `$quantity` it consumed
     */
    public function handle(
        Collection|array $receipts,
        string $quantity,
        User $performedBy,
        ?string $note = null,
        MovementReason $reason = MovementReason::PurchaseReturn,
        mixed $occurredAt = null,
    ): Collection {
        $returnedAt = EffectiveDate::resolve($occurredAt);
        $receipts = collect($receipts)->sortBy('id')->values();

        if ($receipts->isEmpty()) {
            throw new InvalidArgumentException('Cannot reverse a receipt that recorded no stock movement.');
        }

        $remaining = $this->scaled($quantity);

        // ⚠⚠ A quantity that rounds to nothing is not a small return, it is no
        // return. `numeric|min:0` on the screen and `> 0` in the caller both let
        // 0.00004 through, and it scales to 0 here: the loop would break at once,
        // hand back an empty collection WITHOUT throwing, and the caller would
        // record a return line with no movement behind it - permanently reducing
        // what the order line may still return, with no stock moved and no
        // journal posted. Refusing is the only honest answer.
        if ($remaining <= 0) {
            throw new InvalidArgumentException(
                "A return quantity of {$quantity} rounds to zero at four decimal places; nothing would move."
            );
        }

        $written = collect();

        // ⚠⚠ MIXED BATCHES ARE REFUSED, NOT AVERAGED. If some batches on this
        // line froze a conversion factor and others did not - which happens when
        // a delivery arrives BEFORE the product's conversion is recorded and
        // another after - then `$quantity` is in kilograms for one batch and in
        // litres for the other, and there is no single unit the caller could
        // have meant. Reversing anyway debits GRNI in the wrong unit for part of
        // the line, and every journal still balances.
        $factors = collect($receipts)
            ->map(fn (StockMovement $r) => StockBatch::find($r->batch_id)?->conversion_factor);

        if ($factors->filter()->isNotEmpty() && $factors->contains(null)) {
            throw new InvalidArgumentException(
                'This line was received both before and after its unit conversion was recorded, '.
                'so a return quantity has no single unit. Reverse the deliveries separately.'
            );
        }

        foreach ($receipts as $receipt) {
            if ($remaining <= 0) {
                break;
            }

            if ($receipt->direction !== MovementDirection::In) {
                throw new InvalidArgumentException("Movement #{$receipt->id} is not a receipt and cannot be reversed as one.");
            }

            $batch = StockBatch::whereKey($receipt->batch_id)->lockForUpdate()->first();

            if ($batch === null) {
                continue;
            }

            $available = $this->scaled((string) $batch->current_quantity);

            if ($available <= 0) {
                continue;
            }

            // ⚠⚠ C-UOM-8, AND THE UNIT IS DECIDED BY THE BATCH, NOT BY THE
            // PRODUCT. A purchase return is expressed in the unit the SUPPLIER
            // credits: the credit note says 10 kilograms while the warehouse
            // holds litres. Which unit that is, is a fact about the delivery that
            // is being reversed, and the batch froze it.
            //
            // ⚠⚠ An earlier version of this took a flag derived from
            // `$product->isBoughtInAnotherUnit()` - the product's CURRENT,
            // freely editable state. Clearing `purchase_unit_id` on the product
            // screen would then have made a 10 kg credit note remove 10 LITRES
            // from batches that were stocked at 1.0905 litres per kilogram,
            // crediting Stock on Hand about 9% short with every journal
            // balancing. That is the precise failure C-UOM-4 put the factor on
            // the batch to prevent, and reading the product row put it straight
            // back. Immutable history decides; the mutable row does not.
            $factor = $batch->conversion_factor !== null ? (string) $batch->conversion_factor : null;
            $wanted = $factor === null
                ? $remaining
                : $this->scaled(PurchaseUnitConversion::toStockQuantity($this->unscaled($remaining), $factor));

            $take = min($wanted, $available);

            // ⚠ Before conversion existed `$take` could never be zero: the loop
            // top guarantees `$remaining > 0` and the check above guarantees
            // `$available > 0`. Converting broke that, because a small enough
            // purchase quantity against a small enough factor rounds to nothing
            // in stock units - and a zero-quantity movement is a row that says
            // something happened when nothing did.
            if ($take <= 0) {
                continue;
            }

            // ⚠ Slice 4: the batch is not written here. The movement below is
            // what the quantity derives from. Integer scaling is still used to
            // decide how much this layer can give (I2).

            $movement = StockMovement::create([
                'product_id' => $receipt->product_id,
                'warehouse_id' => $receipt->warehouse_id,
                'batch_id' => $batch->id,
                'direction' => MovementDirection::Out,
                'reason' => $reason,
                'quantity' => $this->unscaled($take),
                'unit_cost' => $batch->unit_cost,
                'reverses_movement_id' => $receipt->id,
                'occurred_at' => $returnedAt,
                'performed_by' => $performedBy->id,
                'note' => $note,
            ]);

            // ⚠ How much of the CALLER'S quantity this batch consumed. When the
            // whole wanted amount was taken, that is exactly what was left, and
            // saying so avoids dividing back through the factor and losing a
            // ten-thousandth to rounding on every line.
            $consumed = match (true) {
                $factor === null => $take,
                $take === $wanted => $remaining,
                default => $this->scaled(PurchaseUnitConversion::toPurchaseQuantity($this->unscaled($take), $factor)),
            };

            // ⚠⚠ The movement AND its share of the caller's quantity. A return
            // that spans two batches has to hand back both, or the caller writes
            // down only the first and the ledger values only the first with it.
            $written->push(new ReversedReceipt($movement, $this->unscaled($consumed)));

            $remaining -= $consumed;
        }

        // ⚠⚠ THIS GUARD WAS A NO-OP AND THE ROUND-2 REVIEW CAUGHT IT.
        // `$remaining` is an INT - seeded from `scaled()`, and every arm of the
        // match above yields an int - so `$remaining > 0 && $remaining < 1` was
        // UNSATISFIABLE and never once fired. The comment it carried described
        // precisely the case it failed to catch.
        //
        // ⚠ What it is for: converting purchase units to stock units and back
        // cannot round-trip exactly, so returning the WHOLE of a line delivered
        // in two receipts could leave one ten-thousandth outstanding and throw
        // InsufficientStock for goods that are all physically present.
        //
        // ℹ HONESTY ABOUT ITS REACH: no input has been found that actually
        // leaves a residue here - the `$take === $wanted` arm absorbs it on the
        // final batch, and a search over two-batch splits found none. The
        // tolerance stays because one tick is unambiguously rounding rather than
        // a shortfall, and because a guard that silently does nothing is worse
        // than no guard at all. It is NOT claimed to be exercised.
        if ($remaining > 0 && $remaining <= 1) {
            $remaining = 0;
        }

        if ($remaining > 0) {
            $requested = $this->scaled($quantity);

            throw InsufficientStockException::forProductInWarehouse(
                $receipts->first()->product_id,
                $receipts->first()->warehouse_id,
                (float) $quantity,
                (float) $this->unscaled($requested - $remaining),
            );
        }

        return $written;
    }

    /**
     * Quantities are `decimal(15,4)`, so four decimal places are the whole
     * domain. Scaling to integers makes every comparison and subtraction exact.
     */
    private function scaled(string $quantity): int
    {
        return (int) round(((float) $quantity) * 10000);
    }

    private function unscaled(int $scaled): string
    {
        return number_format($scaled / 10000, 4, '.', '');
    }
}
