<?php

namespace App\Modules\Inventory\Actions\Concerns;

use App\Modules\Inventory\Enums\StockBatchStatus;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
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

            $available = $this->scaleQuantity((string) $batch->current_quantity);
            $take = min($remaining, $available);

            $batch->current_quantity = $this->unscaleQuantity($available - $take);

            if ($available - $take <= 0) {
                $batch->status = StockBatchStatus::Depleted;
            }

            $batch->save();

            $consumed->push(['batch' => $batch, 'quantity' => $this->unscaleQuantity($take)]);
            $remaining -= $take;
        }

        if ($remaining > 0) {
            $available = $this->scaleQuantity($quantity) - $remaining;

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
