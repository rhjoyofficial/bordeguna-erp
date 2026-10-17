<?php

namespace App\Modules\Inventory\Actions\Concerns;

use App\Modules\Inventory\Enums\StockBatchStatus;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\EffectiveDate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

trait ConsumesFifoStock
{
    /**
     * Decrements active batches for the product/warehouse, oldest-expiring
     * first, until $quantity is satisfied. All-or-nothing: throws (and, since
     * callers wrap this in a transaction, rolls back) rather than partially
     * consuming when stock is insufficient.
     *
     * @return Collection<int, array{batch: StockBatch, quantity: string}>
     */
    protected function consumeFifo(Product $product, Warehouse $warehouse, string $quantity, ?Carbon $movedAt = null): Collection
    {
        // ⚠ Integer-scaled arithmetic, not float (I2). Quantities are
        // decimal(15,4), so four places are the entire domain and scaling by
        // 10000 makes every comparison and subtraction exact.
        //
        // ⚠⚠ The float version was NOT producing a live defect, and the review
        // was wrong to say it was: the error cancelled in the remainder, and the
        // `decimal:4` cast on StockBatch re-quantised the 2.78e-17 of dust left
        // in a batch so the depletion check still saw zero. In other words
        // correctness rested on a model cast that nothing declared load-bearing.
        // This removes that dependency rather than fixing a fire. See §12 of the
        // readiness review.
        $remaining = $this->scaleQuantity($quantity);
        $consumed = collect();

        $batches = StockBatch::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            ->consumable()
            ->lockForUpdate()
            ->get();

        // ⭐ Phase 2 (17 Oct 2026, ruling Q1): an APPROVED staff purchase or
        // owner withdrawal holds the exact batch its price was taken from. A
        // sale must not issue that batch out from under it, so what each batch
        // has FREE is its balance less the holds still open on it. A document
        // consuming its own hold releases it first, inside the same
        // transaction (PostOwnerWithdrawal does), so it never blocks itself.
        $held = StockReservation::heldByBatch($batches->pluck('id'));

        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }

            // ⚠ A withdrawal cannot predate the stock it consumes. Taking goods
            // out on the 1st that arrived on the 5th is not a date to sort into
            // place, it is an impossible sequence, and allowing it produces a
            // ledger whose running balance goes negative inside its own history.
            if ($movedAt !== null && $batch->received_at !== null) {
                EffectiveDate::assertNotBefore($movedAt, $batch->received_at, $batch->id);
            }

            $available = $this->scaleQuantity((string) $batch->current_quantity) - $this->scaleQuantity($held[$batch->id] ?? '0');

            if ($available <= 0) {
                continue;
            }

            $take = min($remaining, $available);

            // ⚠⚠ Slice 4: this READS the cache to plan how much to take from
            // which layer, and no longer WRITES it. The movement the caller is
            // about to record is what the new quantity is derived from, via
            // StockMovementObserver. Subtracting here as well would put the two
            // numbers back to being written by the same hand, independently -
            // which is the arrangement slice 4 exists to end.
            $consumed->push(['batch' => $batch, 'quantity' => $this->unscaleQuantity($take)]);
            $remaining -= $take;
        }

        if ($remaining > 0) {
            $available = $this->scaleQuantity($quantity) - $remaining;

            // ⚠ Traceability M2 (17 Oct 2026): when the missing stock is
            // there but ON HOLD, say so. "Only 0 available" beside a full
            // shelf reads as a bug; the person needs to know the batch is
            // held and what to do about it.
            $held = (float) StockBatch::query()
                ->where('product_id', $product->id)
                ->where('warehouse_id', $warehouse->id)
                ->where('status', StockBatchStatus::Active)
                ->whereNotNull('held_at')
                ->sum('current_quantity');

            if ($held > 0) {
                throw InsufficientStockException::heldStock(
                    $product->name,
                    $warehouse->name,
                    (float) $quantity,
                    (float) $this->unscaleQuantity($available),
                    $held,
                );
            }

            throw InsufficientStockException::forProductInWarehouse(
                $product->id,
                $warehouse->id,
                (float) $quantity,
                (float) $this->unscaleQuantity($available),
            );
        }

        return $consumed;
    }

    private function scaleQuantity(string $quantity): int
    {
        return (int) round(((float) $quantity) * 10000);
    }

    private function unscaleQuantity(int $scaled): string
    {
        return number_format($scaled / 10000, 4, '.', '');
    }
}
