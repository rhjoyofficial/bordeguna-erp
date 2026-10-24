<?php

namespace App\Modules\Inventory\Support;

use App\Modules\Inventory\Enums\StockBatchStatus;
use App\Modules\Inventory\Models\BillOfMaterial;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBatch;

/**
 * ⭐ Initial plan B5 (Joy, 24 Oct 2026; R25, R26): what a recipe costs, on the
 * recipe screen. His example, one 1 L bottle of mustard oil:
 *
 *   Raw materials   RAW-OIL-MST-BULK  0.91 kg  x 180.00  = 163.80
 *   Packaging       PKG-PET-BTL-1L    1 pc     x  12.00  =  12.00
 *                   PKG-CAP-28MM      1 pc     x   1.50  =   1.50
 *                   PKG-LBL-LAL-1L    1 pc     x   2.50  =   2.50
 *                   PKG-CRT-MST-12    0.083 pc x  36.00  =   3.00 (2.99)
 *   Subtotal: Direct materials                          = 182.80
 *
 * ⚠⚠ AN ESTIMATE FOR READING, NEVER A POSTED COST. A run is costed from the
 * batches it actually consumes (RecordProductionRun); nothing here is stored
 * or posted, and it changes the moment a new batch arrives.
 *
 * The unit rate, per STOCK unit of the component (the unit the recipe line is
 * stored in):
 *
 * - `latest_batch`: the exact cost of the newest batch received or made.
 *   ⭐ Joy, Q20 (24 Oct 2026): "every batch keeps its own exact cost, no
 *   moving average", so the rate is always one real batch's cost, and says so.
 * - `on_hand_average`: shown BESIDE the rate as a labelled hint only, when
 *   stock is held: the weighted average of the batches still on hand, which
 *   is roughly what a run made today would consume. It never enters a total.
 * - none: never stocked. The line has no cost and the total says it is
 *   incomplete, rather than counting it as zero.
 *
 * Wastage is counted in the recipe, not the purchase cost (Joy, Q17): a
 * line's wastage % raises its input quantity before the rate applies.
 * Line total = quantity per output x (1 + wastage %) x rate, rounded to the
 * paisa. The subtotal is the sum of the rounded line totals, so the figures
 * on the screen add up; cost per unit = subtotal / makes how many.
 */
final class RecipeCosting
{
    public const ON_HAND_AVERAGE = 'on_hand_average';

    public const LATEST_BATCH = 'latest_batch';

    private const SCALE = 8;

    /**
     * The unit rate of each product, per stock unit.
     *
     * @param  array<int, int>  $productIds
     * @return array<int, array{rate: string, basis: string, on_hand_average: ?string}> keyed by product id; never-stocked products are absent
     */
    public static function rates(array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));

        if ($productIds === []) {
            return [];
        }

        $rates = [];

        // The newest batch of each product: its exact cost is THE rate (Q20).
        $newest = StockBatch::query()->whereIn('product_id', $productIds)->groupBy('product_id')->selectRaw('max(id)');

        foreach (StockBatch::query()->whereIn('id', $newest)->get(['product_id', 'unit_cost']) as $batch) {
            $rates[(int) $batch->product_id] = ['rate' => self::round((string) $batch->unit_cost, 4), 'basis' => self::LATEST_BATCH, 'on_hand_average' => null];
        }

        // The on-hand average, a hint beside it. Decimal strings summed in PHP
        // with bcmath (a driver's SUM of a product is a float on SQLite and a
        // decimal on MySQL, and the two disagree in the last places).
        $held = StockBatch::query()
            ->whereIn('product_id', $productIds)
            ->where('status', StockBatchStatus::Active)
            ->where('current_quantity', '>', 0)
            ->get(['product_id', 'current_quantity', 'unit_cost'])
            ->groupBy('product_id');

        foreach ($held as $productId => $batches) {
            $quantity = '0';
            $value = '0';

            foreach ($batches as $batch) {
                $quantity = bcadd($quantity, (string) $batch->current_quantity, self::SCALE);
                $value = bcadd($value, bcmul((string) $batch->current_quantity, (string) $batch->unit_cost, self::SCALE), self::SCALE);
            }

            if (isset($rates[(int) $productId]) && bccomp($quantity, '0', self::SCALE) > 0) {
                $rates[(int) $productId]['on_hand_average'] = self::round(bcdiv($value, $quantity, self::SCALE), 4);
            }
        }

        return $rates;
    }

    /**
     * The costed lines of one recipe, its sections and its totals.
     *
     * @param  array<int, array{rate: string, basis: string, on_hand_average: ?string}>  $rates  from rates()
     * @return array{lines: array<int, array{section: string, unit_rate: ?string, rate_basis: ?string, on_hand_average: ?string, line_total: ?string}>, raw_total: string, packaging_total: string, direct_materials: string, cost_per_unit: ?string, complete: bool}
     */
    public static function forRecipe(BillOfMaterial $bom, array $rates): array
    {
        $lines = [];
        $raw = '0';
        $packaging = '0';
        $complete = true;

        foreach ($bom->lines as $line) {
            /** @var Product|null $component */
            $component = $line->componentProduct;
            $section = $component?->isPackaging() ? 'packaging' : 'raw';
            $rate = $rates[(int) $line->component_product_id] ?? null;
            $total = null;

            if ($rate === null) {
                $complete = false;
            } else {
                $quantity = bcdiv(bcmul((string) $line->quantity_per_output, bcadd('100', (string) ($line->wastage_percent ?? '0'), self::SCALE), self::SCALE), '100', self::SCALE);
                $total = self::round(bcmul($quantity, $rate['rate'], self::SCALE), 2);

                if ($section === 'packaging') {
                    $packaging = bcadd($packaging, $total, 2);
                } else {
                    $raw = bcadd($raw, $total, 2);
                }
            }

            $lines[] = [
                'section' => $section,
                'unit_rate' => $rate['rate'] ?? null,
                'rate_basis' => $rate['basis'] ?? null,
                'on_hand_average' => $rate['on_hand_average'] ?? null,
                'line_total' => $total,
            ];
        }

        $direct = bcadd($raw, $packaging, 2);
        $output = (string) $bom->output_quantity;

        return [
            'lines' => $lines,
            'raw_total' => $raw,
            'packaging_total' => $packaging,
            'direct_materials' => $direct,
            'cost_per_unit' => bccomp($output, '0', self::SCALE) > 0 ? self::round(bcdiv($direct, $output, self::SCALE), 2) : null,
            'complete' => $complete,
        ];
    }

    /** Half up at `$places`, the way the ledger rounds money. */
    private static function round(string $value, int $places): string
    {
        $shift = '0.'.str_repeat('0', $places).'5';

        return bccomp($value, '0', self::SCALE) < 0
            ? bcsub($value, $shift, $places)
            : bcadd($value, $shift, $places);
    }
}
