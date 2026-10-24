<?php

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockReservation;
use App\Modules\Inventory\Models\Warehouse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Hold the FIFO batch slice(s) that WILL be issued for an approved document
 * (Phase 2, 17 Oct 2026; plan F4, shared by staff purchases and owner
 * withdrawals).
 *
 * ⭐ ChatGPT ruling Q1: the price frozen at approval is the cost of the batch
 * reserved for it, so the reservation walks the SAME order `consumeFifo` will
 * walk (`StockBatch::consumable()`: earliest expiry, then oldest receipt),
 * net of what is already held for somebody else. The batch it lands on is the
 * batch the handover issues, unless stock moves underneath it, which the
 * handover checks.
 *
 * ⚠ Under a lock on the batches, inside a transaction: two approvals of the
 * last unit must leave one reservation, not two.
 */
class ReserveStock
{
    /**
     * @return Collection<int, StockReservation> one row per batch slice, FIFO order
     *
     * @throws InsufficientStockException
     */
    public function handle(Product $product, Warehouse $warehouse, string $quantity, string $reason, Model $reference, ?User $by = null): Collection
    {
        return DB::transaction(function () use ($product, $warehouse, $quantity, $reason, $reference, $by) {
            $remaining = (int) round(((float) $quantity) * 10000);
            $batches = StockBatch::query()
                ->where('product_id', $product->id)
                ->where('warehouse_id', $warehouse->id)
                ->consumable()
                ->lockForUpdate()
                ->get();

            $held = StockReservation::liveByBatch($batches->pluck('id')->all());
            $rows = new Collection;

            // ⭐ B17 (25 Oct 2026): a confirmed sales order holds part of the
            // shelf without owning a batch. A staff purchase or an owner
            // withdrawal approved later may not take that part either.
            $orderHolds = (int) round(((float) StockReservation::query()->live()
                ->whereNull('stock_batch_id')
                ->where('product_id', $product->id)
                ->where('warehouse_id', $warehouse->id)
                ->sum('quantity')) * 10000);
            $free = $batches->sum(fn (StockBatch $batch) => (int) round(((float) $batch->current_quantity) * 10000))
                - array_sum(array_map(fn ($q) => (int) round(((float) $q) * 10000), $held))
                - $orderHolds;

            if ($remaining > max(0, $free)) {
                throw InsufficientStockException::forProductInWarehouse(
                    $product->id,
                    $warehouse->id,
                    (float) $quantity,
                    max(0, $free) / 10000,
                );
            }

            foreach ($batches as $batch) {
                if ($remaining <= 0) {
                    break;
                }

                $available = (int) round(((float) $batch->current_quantity) * 10000)
                    - (int) round(((float) ($held[$batch->id] ?? '0')) * 10000);

                if ($available <= 0) {
                    continue;
                }

                $take = min($remaining, $available);
                $remaining -= $take;

                $rows->push(StockReservation::create([
                    'business_id' => $batch->business_id,
                    'stock_batch_id' => $batch->id,
                    'product_id' => $batch->product_id,
                    'warehouse_id' => $batch->warehouse_id,
                    'quantity' => number_format($take / 10000, 4, '.', ''),
                    'reason' => $reason,
                    'reference_type' => $reference->getMorphClass(),
                    'reference_id' => $reference->getKey(),
                    'created_by' => $by?->id,
                ]));
            }

            if ($remaining > 0) {
                throw InsufficientStockException::forProductInWarehouse(
                    $product->id,
                    $warehouse->id,
                    (float) $quantity,
                    (float) $quantity - $remaining / 10000,
                );
            }

            return $rows;
        });
    }
}
