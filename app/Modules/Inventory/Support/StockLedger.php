<?php

namespace App\Modules\Inventory\Support;

use App\Modules\Accounting\Support\Money;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\TenantScope;
use App\Modules\Inventory\Enums\MovementDirection;
use App\Modules\Inventory\Enums\StockBatchStatus;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockMovement;

/**
 * The movement ledger, treated as the single source of truth.
 *
 * ⚠⚠ `stock_batches.current_quantity` is a CACHE. It is derived from movements
 * and never trusted on its own. Before slice 4 the two were written by the same
 * hand, independently - `consumeFifo` subtracted from the batch and the caller
 * wrote a movement beside it - so if they ever disagreed nothing noticed and the
 * cache won.
 *
 * Joy's rule: "If the cache and the ledger ever disagree, treat that as a
 * consistency defect, not as two equally valid sources."
 *
 * See LEDGER-AS-SOURCE-OF-TRUTH-ARCHITECTURE.md.
 */
class StockLedger
{
    /**
     * What the movements say this batch holds. The authoritative number.
     */
    public static function balanceForBatch(StockBatch $batch): string
    {
        $in = (float) StockMovement::withoutGlobalScopes()
            ->where('batch_id', $batch->id)
            ->where('direction', MovementDirection::In)
            ->sum('quantity');

        $out = (float) StockMovement::withoutGlobalScopes()
            ->where('batch_id', $batch->id)
            ->where('direction', MovementDirection::Out)
            ->sum('quantity');

        return self::format($in - $out);
    }

    /**
     * ⭐ 26 Oct 2026: what the movements say this batch is WORTH, in paisa:
     * every movement valued and rounded once (StockValue), summed.
     */
    public static function valueForBatch(StockBatch $batch): int
    {
        return StockValue::sum(
            StockMovement::withoutGlobalScopes()->where('batch_id', $batch->id)->get(StockValue::columns()),
        )->minor;
    }

    /**
     * Brings one batch's cache back in line with its ledger.
     *
     * ⚠ Status is derived here too. A batch is depleted when its BALANCE reaches
     * zero, not when a particular code path happened to notice - two classes
     * used to make that call independently and could disagree.
     *
     * ⚠ Written with the query builder, not the model, so this cannot retrigger
     * the observer that calls it.
     */
    public static function syncBatch(StockBatch $batch): string
    {
        $balance = self::balanceForBatch($batch);

        // ⭐ 26 Oct 2026: the value is derived the same way and at the same
        // moment, so the batch's worth can never drift from its movements.
        $attributes = ['current_quantity' => $balance, 'current_value' => self::valueForBatch($batch)];

        // Only ever moves Active → Depleted and back. An Expired batch keeps its
        // status: expiry is a fact about a date, not about a balance.
        if ($batch->status !== StockBatchStatus::Expired) {
            $attributes['status'] = (float) $balance <= 0
                ? StockBatchStatus::Depleted->value
                : StockBatchStatus::Active->value;
        }

        StockBatch::withoutGlobalScopes()->whereKey($batch->id)->update($attributes);

        return $balance;
    }

    /**
     * Rebuilds every batch in the current business from the ledger alone.
     *
     * ⚠ This is NOT a stock edit and must not be read as one. Joy's standing
     * rule forbids changing a quantity without a movement to explain it; a
     * rebuild changes nothing in reality, it makes a stale copy agree with the
     * movements that were always there. It is the ledger asserting itself.
     *
     * @return int how many batches were actually corrected
     */
    public static function rebuildAll(): int
    {
        $corrected = 0;

        foreach (StockBatch::query()->orderBy('id')->get() as $batch) {
            $before = self::format((float) $batch->current_quantity);
            $valueBefore = (int) $batch->current_value;
            $after = self::syncBatch($batch);

            if ($before !== $after || $valueBefore !== self::valueForBatch($batch)) {
                $corrected++;
            }
        }

        return $corrected;
    }

    /**
     * The three-level integrity chain. Returns a description of every problem
     * found, empty when the chain holds end to end.
     *
     * @return array<int, string>
     */
    public static function verify(): array
    {
        return [
            ...self::verifyBatchesAgainstLedger(),
            ...self::verifyWarehouseTotals(),
            ...self::verifyProductTotals(),
        ];
    }

    /**
     * ⚠⚠ Runs cold, which is the only way a console command or the scheduler
     * will ever call it. Without entering each tenant, every model here is
     * business-scoped, BusinessScope compiles `1 = 0`, and the verifier reports
     * "all consistent" while examining NOTHING - the worst possible failure for
     * an integrity check.
     *
     * @return array<int, string>
     */
    public static function verifyAllBusinesses(): array
    {
        $problems = [];

        foreach (Business::withoutGlobalScopes()->where('is_active', true)->orderBy('id')->get() as $business) {
            TenantScope::enter($business->id);

            foreach (self::verify() as $problem) {
                $problems[] = "[{$business->name}] {$problem}";
            }
        }

        TenantScope::leave();

        return $problems;
    }

    /** @return array<int, string> Level 1: a batch against its own movements. */
    private static function verifyBatchesAgainstLedger(): array
    {
        $problems = [];

        foreach (StockBatch::query()->orderBy('id')->get() as $batch) {
            $ledger = self::balanceForBatch($batch);
            $cache = self::format((float) $batch->current_quantity);

            if ($ledger !== $cache) {
                $problems[] = "batch #{$batch->id}: ledger says {$ledger}, cache says {$cache}";
            }

            // ⭐ 26 Oct 2026: the value too, and an empty batch is worth
            // nothing. Value left on a batch with no units is dust that Stock
            // on Hand would carry forever (inventory:sweep-batch-dust).
            $value = self::valueForBatch($batch);

            if ($value !== (int) $batch->current_value) {
                $problems[] = "batch #{$batch->id}: ledger says it is worth ".Money::ofMinor($value)->format().', cache says '.Money::ofMinor((int) $batch->current_value)->format();
            }

            if ($ledger === self::format(0) && $value !== 0) {
                $problems[] = "batch #{$batch->id}: holds nothing but is worth ".Money::ofMinor($value)->format().' - run inventory:sweep-batch-dust';
            }
        }

        return $problems;
    }

    /**
     * Level 2: warehouse totals.
     *
     * ⚠ A genuine cross-check rather than the same number twice, because a
     * movement carries its OWN `warehouse_id`, independent of its batch's. A
     * transfer leg written against the wrong warehouse passes level 1 and fails
     * here.
     *
     * @return array<int, string>
     */
    private static function verifyWarehouseTotals(): array
    {
        $problems = [];

        $fromBatches = StockBatch::query()
            ->selectRaw('product_id, warehouse_id, SUM(current_quantity) as total')
            ->groupBy('product_id', 'warehouse_id')
            ->get()
            ->mapWithKeys(fn ($row) => ["{$row->product_id}:{$row->warehouse_id}" => self::format((float) $row->total)]);

        $fromLedger = StockMovement::query()
            ->selectRaw("product_id, warehouse_id, SUM(CASE WHEN direction = 'in' THEN quantity ELSE -quantity END) as total")
            ->groupBy('product_id', 'warehouse_id')
            ->get()
            ->mapWithKeys(fn ($row) => ["{$row->product_id}:{$row->warehouse_id}" => self::format((float) $row->total)]);

        foreach ($fromLedger->keys()->merge($fromBatches->keys())->unique() as $key) {
            [$productId, $warehouseId] = explode(':', $key);

            $ledger = $fromLedger[$key] ?? '0.0000';
            $batches = $fromBatches[$key] ?? '0.0000';

            if ($ledger !== $batches) {
                $problems[] = "product #{$productId} in warehouse #{$warehouseId}: ledger says {$ledger}, batches say {$batches}";
            }
        }

        return $problems;
    }

    /**
     * Level 3: the number a user actually sees.
     *
     * ⚠ Checks the PRESENTATION path, which is the one that has produced a
     * defect twice on this project. A chain that verifies the data but not the
     * method people read it through is not a complete chain.
     *
     * @return array<int, string>
     */
    private static function verifyProductTotals(): array
    {
        $problems = [];

        foreach (Product::query()->stocked()->get() as $product) {
            $shown = (float) $product->currentStockQuantity();

            $fromWarehouses = (float) StockBatch::query()
                ->where('product_id', $product->id)
                ->where('status', StockBatchStatus::Active)
                ->sum('current_quantity');

            if (self::format($shown) !== self::format($fromWarehouses)) {
                $problems[] = "product #{$product->id} ({$product->sku}): shown as {$shown}, warehouses total ".self::format($fromWarehouses);
            }
        }

        return $problems;
    }

    private static function format(float $value): string
    {
        return number_format($value, 4, '.', '');
    }
}
