<?php

namespace App\Modules\Inventory\Support;

use App\Modules\Inventory\Enums\AdjustmentDirection;
use App\Modules\Inventory\Models\InventoryAdjustment;

/**
 * Stock corrections raised but not yet approved, as units (INV-002, 14 Oct 2026).
 *
 * ⚠⚠ A count sized its variance against the BOOK alone. A first count (book
 * 10, counted 6) raised a decrease of 4 that waited for approval; a second
 * count before that approval saw the book still at 10, counted 6 again, and
 * raised another 4. Approving both left the book at 2 with 6 on the shelf.
 * A count therefore measures against what the book WILL say once everything
 * already waiting is approved: the book, plus pending increases, less pending
 * decreases (a count variance, a manual correction, or the nightly expiry
 * proposal alike). A pending correction that is later REJECTED leaves the gap
 * it described, which the next count then finds and raises again.
 *
 * A revaluation moves money, not units, and its quantity is 0, so it adds
 * nothing here.
 */
final class PendingAdjustments
{
    /**
     * Net pending units per product in the warehouse, positive when the
     * waiting corrections would add stock.
     *
     * @param  list<int>|null  $productIds
     * @return array<int, float>
     */
    public static function netByProduct(int $warehouseId, ?array $productIds = null): array
    {
        $net = [];

        $pending = InventoryAdjustment::query()
            ->without('approvalRequest')
            ->where('warehouse_id', $warehouseId)
            ->when($productIds !== null, fn ($query) => $query->whereIn('product_id', $productIds))
            ->whereHas('approvalRequest', fn ($query) => $query->where('status', 'pending'))
            ->get(['product_id', 'direction', 'quantity']);

        foreach ($pending as $adjustment) {
            $units = (float) $adjustment->quantity;
            $net[$adjustment->product_id] = ($net[$adjustment->product_id] ?? 0.0)
                + ($adjustment->direction === AdjustmentDirection::Increase ? $units : -$units);
        }

        return $net;
    }
}
