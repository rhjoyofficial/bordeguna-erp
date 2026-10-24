<?php

namespace App\Support\Traceability;

use App\Modules\Inventory\Enums\MovementDirection;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Purchasing\Models\GoodsReceiptLine;

/**
 * ⭐ Initial plan B8 (Joy, 24 Oct 2026; R31): the supplier each batch was
 * bought from, for the batch pickers ("supplier, own cost, expiry, quantity
 * left"). A batch moved between warehouses is followed back to the batch that
 * arrived on the goods receipt (source_batch_id), the rule BatchTrace's
 * backward trace uses. Null for opening stock, a production output or a
 * receipt with no purchase order behind it.
 *
 * Three queries for any number of batches: the picker lists a whole product.
 */
final class BatchSuppliers
{
    /**
     * @param  list<int>  $batchIds
     * @return array<int, string|null> batch id => supplier name
     */
    public static function for(array $batchIds): array
    {
        if ($batchIds === []) {
            return [];
        }

        // Follow transfers back to the batch that came in on a receipt.
        $root = [];
        $parents = StockBatch::query()->whereIn('id', $batchIds)->pluck('source_batch_id', 'id')->all();

        foreach ($batchIds as $id) {
            $root[$id] = $id;
        }

        for ($depth = 0; $depth < 10; $depth++) {
            $pending = array_filter($root, fn (int $current) => ! empty($parents[$current] ?? null));

            if ($pending === []) {
                break;
            }

            foreach ($pending as $id => $current) {
                $root[$id] = (int) $parents[$current];
            }

            $missing = array_values(array_diff(array_unique(array_values($root)), array_keys($parents)));
            if ($missing !== []) {
                $parents += StockBatch::query()->whereIn('id', $missing)->pluck('source_batch_id', 'id')->all();
            }
        }

        $firstIn = StockMovement::query()
            ->whereIn('batch_id', array_unique(array_values($root)))
            ->where('direction', MovementDirection::In)
            ->selectRaw('batch_id, MIN(id) as first_id')
            ->groupBy('batch_id')
            ->pluck('first_id', 'batch_id')
            ->all();

        $supplierByMovement = GoodsReceiptLine::query()
            ->whereIn('stock_movement_id', array_values($firstIn))
            ->with('goodsReceipt.purchaseOrder.supplier:id,name')
            ->get()
            ->mapWithKeys(fn (GoodsReceiptLine $line) => [(int) $line->stock_movement_id => $line->goodsReceipt?->purchaseOrder?->supplier?->name])
            ->all();

        $names = [];

        foreach ($root as $id => $origin) {
            $movement = $firstIn[$origin] ?? null;
            $names[$id] = $movement === null ? null : ($supplierByMovement[(int) $movement] ?? null);
        }

        return $names;
    }
}
