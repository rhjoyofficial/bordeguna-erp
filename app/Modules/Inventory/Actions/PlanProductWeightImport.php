<?php

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Support\ProductWeight;
use App\Modules\Inventory\Support\ProductWeightImportPlan;

/**
 * Works out what a weights sheet would change, and writes nothing.
 *
 * - Matched on SKU, within the current business only (the global scope).
 * - A blank weight leaves the product alone: the template prints every
 *   product, and most rows come back untouched on the first pass.
 * - Whole grams, 1 to 1,000,000. "450g", "0.45" and "-1" are errors, listed
 *   row by row, never guessed at.
 * - The same SKU twice is an error: which of the two weights is right is
 *   exactly what nobody can tell afterwards.
 *
 * ⚠ Re-derived from the live database every time, the importers' rule: the
 * plan is never carried from the preview to the apply.
 */
class PlanProductWeightImport
{
    /**
     * @param  list<array{line:int, values:array<string,string>}>  $rows
     */
    public function handle(array $rows): ProductWeightImportPlan
    {
        $plan = new ProductWeightImportPlan;
        $products = Product::query()->get(['id', 'sku', 'name', 'weight_grams'])->keyBy(fn (Product $p) => strtoupper($p->sku));
        $seen = [];

        foreach ($rows as $row) {
            $line = $row['line'];
            $sku = strtoupper($row['values']['sku'] ?? '');
            $raw = $row['values']['weight_grams'] ?? '';

            if ($sku === '') {
                $plan->addError($line, '', 'The row has no SKU.');

                continue;
            }

            if (isset($seen[$sku])) {
                $plan->addError($line, $sku, "This SKU is already on line {$seen[$sku]}. Keep one row per product.");

                continue;
            }

            $seen[$sku] = $line;
            $product = $products[$sku] ?? null;

            if ($product === null) {
                $plan->addError($line, $sku, 'No product with this SKU exists in this business. The weights sheet never creates products.');

                continue;
            }

            if ($raw === '') {
                $plan->blank[] = ['line' => $line, 'sku' => $product->sku];

                continue;
            }

            if (! ctype_digit($raw) || (int) $raw < 1 || (int) $raw > ProductWeight::MAX_GRAMS) {
                $plan->addError($line, $product->sku, "weight_grams \"{$raw}\" must be whole grams between 1 and ".ProductWeight::MAX_GRAMS.' (no unit, no decimals).');

                continue;
            }

            if ((int) $raw === $product->weight_grams) {
                $plan->unchanged++;

                continue;
            }

            $plan->changes[] = [
                'line' => $line,
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'from' => $product->weight_grams,
                'to' => (int) $raw,
            ];
        }

        return $plan;
    }
}
