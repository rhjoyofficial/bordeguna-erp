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
     * @return Collection<int, StockMovement> one movement per batch touched
     */
    public function handle(
        Collection|array $receipts,
        string $quantity,
        User $performedBy,
        ?string $note = null,
        MovementReason $reason = MovementReason::PurchaseReturn,
        mixed $occurredAt = null,
        // ⚠⚠ C-UOM-8. A purchase return is expressed in the unit the SUPPLIER
        // credits, which for converted goods is not the unit stock is held in:
        // the credit note says 10 kilograms, the warehouse holds litres. When
        // this is true, `$quantity` is in purchase units and each batch converts
        // it with ITS OWN stored factor - never with whatever factor happens to
        // be current, which is the whole reason the factor lives on the batch.
        bool $quantityIsInPurchaseUnits = false,
    ): Collection {
        $returnedAt = EffectiveDate::resolve($occurredAt);
        $receipts = collect($receipts)->sortBy('id')->values();

        if ($receipts->isEmpty()) {
            throw new InvalidArgumentException('Cannot reverse a receipt that recorded no stock movement.');
        }

        $remaining = $this->scaled($quantity);
        $written = collect();

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

            // ⚠ C-UOM-8. `$remaining` counts in purchase units when converting,
            // so it must be turned into stock units at THIS batch's factor before
            // it can be compared with what the batch holds. Two batches of the
            // same product may carry different factors, which is exactly the case
            // this loop has to get right.
            $factor = $quantityIsInPurchaseUnits ? (string) ($batch->conversion_factor ?? '1') : null;
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

            $written->push(StockMovement::create([
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
            ]));

            // ⚠ Decrement in the unit `$remaining` is counted in. When the whole
            // wanted amount was taken, the purchase-unit amount consumed is
            // exactly what was left, and saying so avoids dividing back through
            // the factor and losing a ten-thousandth to rounding on every line.
            if ($factor === null || $take === $wanted) {
                $remaining -= $factor === null ? $take : $remaining;
            } else {
                $remaining -= $this->scaled(PurchaseUnitConversion::toPurchaseQuantity($this->unscaled($take), $factor));
            }
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
