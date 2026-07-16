<?php

namespace App\Modules\Inventory\Actions\Concerns;

use App\Modules\Inventory\Enums\StockBatchStatus;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\Warehouse;
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
    protected function consumeFifo(Product $product, Warehouse $warehouse, string $quantity): Collection
    {
        $remaining = (float) $quantity;
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

            $take = min($remaining, (float) $batch->current_quantity);
            $batch->current_quantity = (float) $batch->current_quantity - $take;

            if ($batch->current_quantity <= 0) {
                $batch->current_quantity = 0;
                $batch->status = StockBatchStatus::Depleted;
            }

            $batch->save();

            $consumed->push(['batch' => $batch, 'quantity' => (string) $take]);
            $remaining -= $take;
        }

        if ($remaining > 0) {
            $available = (float) $quantity - $remaining;

            throw InsufficientStockException::forProductInWarehouse($product->id, $warehouse->id, (float) $quantity, $available);
        }

        return $consumed;
    }
}
