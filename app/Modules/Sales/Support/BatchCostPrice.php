<?php

namespace App\Modules\Sales\Support;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockReservation;

/**
 * ⭐ THE OWNER PRICE (D-B, Joy, 18 Oct 2026: "Cost price"): what the FIFO
 * batch(es) this sale will consume cost the business, per unit.
 *
 * Bought goods: the receipt batch cost, landed cost already capitalised on
 * it included. Made goods: the production output batch cost. Both are
 * `stock_batches.unit_cost`, so one read answers both.
 *
 * ⚠ Walks the SAME order the issue walks (`StockBatch::consumable()`:
 * earliest expiry, oldest receipt, no held or expired batch), net of what an
 * approved document already reserves, in the chosen warehouse. A quantity
 * that spans two batches is priced at the weighted cost of the slices it
 * takes, so the line's revenue equals the cost of goods it releases: zero
 * margin, by design. Never the newest batch, never an average over the
 * shelf, never the MRP.
 *
 * ℹ No warehouse named (a price check before one is chosen): every warehouse
 * of the business in the same order, labelled the same way. The counter and
 * the order always name theirs.
 */
final class BatchCostPrice
{
    /**
     * @return array{unit_cost: string, batch_id: int, batch_number: ?string, batches: int}|null
     *                                                                                           null when nothing can be issued
     */
    public static function for(Product $product, ?int $warehouseId, string $quantity = '1'): ?array
    {
        $batches = StockBatch::query()
            ->withoutGlobalScopes()
            ->where('business_id', $product->business_id)
            ->where('product_id', $product->id)
            ->when($warehouseId !== null, fn ($query) => $query->where('warehouse_id', $warehouseId))
            ->consumable()
            ->get(['stock_batches.id', 'stock_batches.batch_number', 'stock_batches.unit_cost', 'stock_batches.current_quantity']);

        $held = StockReservation::liveByBatch($batches->pluck('id')->all());
        $wanted = max(1, (int) round(((float) $quantity) * 10000));
        $remaining = $wanted;
        $value = '0';
        $taken = 0;
        $first = null;
        $last = null;
        $used = 0;

        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }

            $free = (int) round(((float) $batch->current_quantity) * 10000) - (int) round(((float) ($held[$batch->id] ?? '0')) * 10000);

            if ($free <= 0) {
                continue;
            }

            $take = min($remaining, $free);
            $remaining -= $take;
            $taken += $take;
            $used++;
            $first ??= $batch;
            $last = $batch;
            $value = bcadd($value, bcmul((string) $batch->unit_cost, (string) $take, 6), 6);
        }

        if ($first === null) {
            return null;
        }

        // ⚠ More wanted than is free: the rest is priced at the last free
        // batch's cost. The sale itself is then refused for stock, so this only
        // keeps a price check from answering "no price" for a stock question.
        if ($remaining > 0) {
            $value = bcadd($value, bcmul((string) $last->unit_cost, (string) $remaining, 6), 6);
            $taken += $remaining;
        }

        return [
            'unit_cost' => bcadd(bcdiv($value, (string) $taken, 6), '0', 4),
            'batch_id' => (int) $first->id,
            'batch_number' => $first->batch_number,
            'batches' => $used,
        ];
    }
}
