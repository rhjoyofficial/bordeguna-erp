<?php

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Foundation\Models\Reason;
use App\Modules\Inventory\Enums\AdjustmentDirection;
use App\Modules\Inventory\Enums\InventoryAdjustmentType;
use App\Modules\Inventory\Enums\StockBatchStatus;
use App\Modules\Inventory\Enums\StockCountStatus;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockCount;
use App\Modules\Inventory\Models\StockCountLine;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Turns a finished count into corrections.
 *
 * ⚠⚠ EVERY VARIANCE GOES THROUGH `CreateInventoryAdjustment`, the same action
 * the manual form calls, so approval routing, thresholds, self-approval,
 * movements, journals and audit are IDENTICAL to a hand-typed correction. The
 * count itself never touches stock.
 *
 * ⚠⚠ C-CNT-2: the variance is sized against LIVE stock at this moment, never
 * the snapshot. Stock legitimately sold mid-count must not be "corrected"
 * back. The live figure is stored on the line so the arithmetic stays
 * auditable.
 */
class CompleteStockCount
{
    public function __construct(private readonly CreateInventoryAdjustment $adjust) {}

    public function handle(StockCount $count, Reason $reason, User $completedBy): StockCount
    {
        if (! $count->isDraft()) {
            throw new DomainException("Stock count {$count->count_number} is already {$count->status->value}.");
        }

        $counted = $count->lines()->whereNotNull('counted_quantity')->with('product')->get();

        return DB::transaction(function () use ($count, $reason, $completedBy, $counted) {
            // C-CNT-4 first, so a refusal arrives before any adjustment posts:
            // an increase creates value out of nothing and the cost is never
            // invented.
            $missingCost = [];

            foreach ($counted as $line) {
                $line->live_quantity = $this->live($count, $line);

                if ($this->variance($line) > 0 && $line->unit_cost === null) {
                    $missingCost[] = $line->product->sku;
                }
            }

            if ($missingCost !== []) {
                throw new DomainException(
                    'These lines were counted ABOVE the book quantity and need a unit cost before the found stock can be valued: '
                    .implode(', ', $missingCost).'.'
                );
            }

            foreach ($counted as $line) {
                $variance = $this->variance($line);
                $line->save();

                if ($variance === 0.0) {
                    continue;
                }

                $this->adjust->handle(
                    type: InventoryAdjustmentType::StockCountVariance,
                    direction: $variance > 0 ? AdjustmentDirection::Increase : AdjustmentDirection::Decrease,
                    product: $line->product,
                    warehouse: $count->warehouse,
                    quantity: number_format(abs($variance), 4, '.', ''),
                    reason: $reason,
                    createdBy: $completedBy,
                    unitCost: $variance > 0 ? (string) $line->unit_cost : null,
                    note: "Stock count {$count->count_number}",
                    source: $line,
                );
            }

            $count->update([
                'status' => StockCountStatus::Completed,
                'reason_id' => $reason->id,
                'completed_by' => $completedBy->id,
                'completed_at' => now(),
            ]);

            return $count->fresh(['lines']);
        });
    }

    private function live(StockCount $count, StockCountLine $line): string
    {
        return (string) (StockBatch::query()
            ->where('warehouse_id', $count->warehouse_id)
            ->where('product_id', $line->product_id)
            ->where('status', StockBatchStatus::Active)
            ->sum('current_quantity') ?? '0');
    }

    /** counted minus live, at 4 decimals - positive means found stock. */
    private function variance(StockCountLine $line): float
    {
        return round((float) $line->counted_quantity - (float) $line->live_quantity, 4);
    }
}
