<?php

namespace App\Modules\Inventory\Support;

use App\Modules\Inventory\Models\BillOfMaterial;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Models\StockReservation;

/**
 * "Can make N now, limited by X" (PR3, 18 Oct 2026; Joy: "when I add qty of
 * them, auto calculate how many or how much product can be made").
 *
 * The ERPNext BOM Stock Analysis rule: for each component of the active
 * recipe, the shelf divided by what ONE unit of output needs (wastage
 * included), rounded down; the smallest of those is the answer, and that
 * component is the one holding the run back.
 *
 * ⚠ The shelf is what a run could actually take: consumable batches only (not
 * on hold, not expired, the same scope FIFO consumes through), less stock
 * reserved for an approved request. A figure the confirm then refuses would be
 * a promise this screen cannot keep.
 *
 * ⚠ Units, as the recipe has them: every figure is in the component's own
 * stock unit (C-BOM-4), so nothing here converts. A component counted in whole
 * units is filled UP to a whole number by BomSuggestion, so the run may only
 * count whole units of its shelf; an output counted in whole units is rounded
 * DOWN to a whole number. With those two rules the run this suggests is a run
 * the stock can cover.
 *
 * bcmath throughout, at four places, like every quantity column.
 */
final class MaxMakeable
{
    private const SCALE = 4;

    private const WORKING_SCALE = 12;

    /**
     * Consumable layers per warehouse per product, in the order FIFO/FEFO
     * consumes them, each with what is free of reservations and its unit cost.
     * The production run screen prices its live cost preview from these.
     *
     * @param  list<int>|null  $productIds
     * @return array<int, array<int, list<array{quantity: string, unit_cost: string}>>>
     */
    public static function layers(?int $warehouseId = null, ?array $productIds = null): array
    {
        $batches = StockBatch::query()
            ->consumable()
            ->when($warehouseId !== null, fn ($q) => $q->where('stock_batches.warehouse_id', $warehouseId))
            ->when($productIds !== null, fn ($q) => $q->whereIn('stock_batches.product_id', $productIds))
            ->get(['id', 'product_id', 'warehouse_id', 'current_quantity', 'unit_cost']);

        $held = StockReservation::liveByBatch($batches->pluck('id')->all());
        $layers = [];

        foreach ($batches as $batch) {
            $free = bcsub(self::clean((string) $batch->current_quantity), self::clean($held[$batch->id] ?? '0'), self::SCALE);

            if (bccomp($free, '0', self::SCALE) <= 0) {
                continue;
            }

            $layers[(int) $batch->warehouse_id][(int) $batch->product_id][] = [
                'quantity' => $free,
                'unit_cost' => self::clean((string) $batch->unit_cost),
            ];
        }

        return $layers;
    }

    /**
     * What a run could take of each product, per warehouse.
     *
     * @param  array<int, array<int, list<array{quantity: string, unit_cost: string}>>>|null  $layers
     * @return array<int, array<int, string>> warehouse id => product id => quantity
     */
    public static function available(?array $layers = null): array
    {
        $layers ??= self::layers();
        $available = [];

        foreach ($layers as $warehouseId => $products) {
            foreach ($products as $productId => $productLayers) {
                $sum = '0';

                foreach ($productLayers as $layer) {
                    $sum = bcadd($sum, $layer['quantity'], self::SCALE);
                }

                $available[$warehouseId][$productId] = $sum;
            }
        }

        return $available;
    }

    /** Counted in whole units on the shelf: a count stock unit, fractions not allowed. */
    public static function wholeOnly(Product $product): bool
    {
        $product->loadMissing('unitOfMeasure');

        return (bool) $product->unitOfMeasure?->isCount() && ! $product->allows_fractional_stock;
    }

    /**
     * The most output this recipe can make from `$available` (product id =>
     * quantity in its stock unit), and the component that limits it.
     *
     * Null when the recipe has no component that needs anything (nothing to
     * be limited by).
     *
     * @param  array<int, string>  $available
     * @return array{quantity: string, limited_by_product_id: int, limited_by: ?string}|null
     */
    public static function forRecipe(BillOfMaterial $bom, array $available): ?array
    {
        $bom->loadMissing(['lines.componentProduct.unitOfMeasure', 'outputProduct.unitOfMeasure']);

        $best = null;
        $limit = null;

        foreach ($bom->lines as $line) {
            $component = $line->componentProduct;

            if ($component === null) {
                continue;
            }

            $perUnit = BomSuggestion::perOutputUnit(
                self::clean((string) $line->quantity_per_output),
                self::clean((string) $bom->output_quantity),
                self::clean((string) $line->wastage_percent),
            );

            if (bccomp($perUnit, '0', self::WORKING_SCALE) <= 0) {
                continue;
            }

            $shelf = self::clean($available[$component->id] ?? '0');

            if (bccomp($shelf, '0', self::SCALE) < 0) {
                $shelf = '0';
            }

            // Whole jars only: a half jar on the shelf is not one a run can take.
            if (self::wholeOnly($component)) {
                $shelf = bcadd($shelf, '0', 0);
            }

            // bcdiv truncates, which is the rounding down wanted here.
            $makes = bcdiv($shelf, $perUnit, self::SCALE);

            if ($best === null || bccomp($makes, $best, self::SCALE) < 0) {
                $best = $makes;
                $limit = $component;
            }
        }

        if ($best === null || $limit === null) {
            return null;
        }

        if ($bom->outputProduct !== null && self::wholeOnly($bom->outputProduct)) {
            $best = bcadd($best, '0', 0);
        }

        return [
            'quantity' => self::trim(bcadd($best, '0', self::SCALE)),
            'limited_by_product_id' => (int) $limit->id,
            'limited_by' => $limit->name,
        ];
    }

    /** `20.0000` → `20`, `20.5000` → `20.5`. */
    private static function trim(string $value): string
    {
        if (! str_contains($value, '.')) {
            return $value;
        }

        $trimmed = rtrim(rtrim($value, '0'), '.');

        return ($trimmed === '' || $trimmed === '-') ? '0' : $trimmed;
    }

    /** A decimal string as bcmath wants it. */
    private static function clean(string $value): string
    {
        $value = trim($value);

        return ($value === '' || ! is_numeric($value)) ? '0' : (stripos($value, 'e') !== false ? number_format((float) $value, self::SCALE, '.', '') : $value);
    }
}
