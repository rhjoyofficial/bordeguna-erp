<?php

namespace App\Modules\Sales\Actions;

use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Models\PriceGroup;
use App\Modules\Sales\Models\ProductPrice;
use App\Modules\Sales\Support\PriceImportPlan;
use App\Modules\Sales\Support\PriceListFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Works out exactly what a price file would do, and writes nothing.
 *
 * ⚠⚠ MATCHING IS ON SKU, WHICH IS ASCII. Joy's decision of 2026-08-13, and the
 * reason is concrete: the ERP stores decomposed Bangla (য + nukta) while the
 * spreadsheet stores the precomposed form, so identical-looking names never
 * compare equal. A name-keyed importer would have reported live products as new
 * and created a duplicate for every product whose name contains য়, ড় or ঢ়.
 * Names are carried into the plan for a human to verify, never to match on.
 *
 * ⚠⚠ THIS IMPORTER NEVER CREATES A PRODUCT. A price for a SKU that does not
 * exist is an error, not an invitation. Creating a product from a price file
 * would mean inventing its unit, category and type - business data nobody
 * supplied.
 *
 * C-PRI-1  A price column maps to a price-group CODE, resolved to a real
 *          `price_groups` row. A missing group is an ERROR, never a silent
 *          fallback to the default tier: free text on both sides of a match
 *          that decides margin is what `price_groups` was created to end.
 * C-PRI-2  A row that supersedes an existing price CLOSES it the day before the
 *          new one starts. It never edits or deletes it, because an invoice
 *          raised in September must still be explicable in December.
 * C-PRI-3  A BLANK price cell means "no rule for this tier", never zero. A zero
 *          would be a real, sellable price of nothing.
 * C-PRI-4  An inactive product is skipped with a warning. Joy excluded inactive
 *          products from the go-live catalogue, and pricing one would contradict
 *          that silently.
 * C-PRI-5  No ungrouped (null price-group) row is written, and none is needed:
 *          `ResolveSellingPrice::groupIdFor()` falls a customer with no group
 *          back to the RETAIL group, so the RETAIL rows already cover walk-in
 *          and every ungrouped customer. Verified, not assumed.
 */
class PlanPriceListImport
{
    public function handle(array $rows, array $ignoredColumns, Carbon $effectiveFrom): PriceImportPlan
    {
        $plan = new PriceImportPlan;
        $plan->ignoredColumns = $ignoredColumns;

        $groups = PriceGroup::query()->get()->keyBy(fn (PriceGroup $g) => strtoupper($g->code));
        $products = Product::query()->get()->keyBy(fn (Product $p) => strtoupper($p->sku));

        // Every rule already in force on the day the new prices start.
        $existing = ProductPrice::query()->inForceOn($effectiveFrom)->get();

        $pricedProductIds = [];
        $seen = [];

        foreach ($rows as $row) {
            $line = $row['line'];
            $values = $row['values'];
            $sku = strtoupper($values['sku'] ?? '');

            if ($sku === '') {
                $plan->addError($line, '', 'The row has no SKU.');

                continue;
            }

            $product = $products[$sku] ?? null;

            if ($product === null) {
                // An unknown SKU the file ITSELF marks inactive is the approved
                // "inactive and never created" catalogue decision showing up in
                // the price columns - the product deliberately does not exist,
                // so there is nothing to price and nothing to block the file
                // over. An unknown SKU on an ACTIVE row keeps the hard error:
                // that is the typo this rule exists to catch.
                if ($this->rowMarkedInactive($values)) {
                    $plan->addWarning($line, $sku, 'No product with this SKU exists and the row is marked inactive; skipped (an inactive product that never existed is not priced).');

                    continue;
                }

                $plan->addError($line, $sku, 'No product with this SKU exists in this business. A price file never creates products.');

                continue;
            }

            // C-PRI-4.
            if (! $product->is_active) {
                $plan->addWarning($line, $sku, 'Product is inactive; its prices were skipped.');

                continue;
            }

            $minQuantity = $this->minQuantity($values, $plan, $line, $sku);

            if ($minQuantity === null) {
                continue;
            }

            $rowPricedAnything = false;

            foreach (PriceListFile::PRICE_COLUMNS as $column => $code) {
                if (! array_key_exists($column, $values)) {
                    continue;
                }

                $raw = $values[$column];

                // C-PRI-3. Blank is silence, not zero.
                if ($raw === '') {
                    continue;
                }

                if (! is_numeric($raw) || (float) $raw <= 0) {
                    $plan->addError($line, $sku, "{$column} is '{$raw}'. A selling price must be a number greater than zero.");

                    continue;
                }

                $group = $groups[$code] ?? null;

                // C-PRI-1.
                if ($group === null) {
                    $plan->addError($line, $sku, "Price group '{$code}' does not exist in this business, so {$column} cannot be imported.");

                    continue;
                }

                $key = $product->id.'|'.$group->id.'|'.$this->scale($minQuantity);

                if (isset($seen[$key])) {
                    $plan->addError($line, $sku, "The file prices {$code} for this product twice at the same minimum quantity (first on line {$seen[$key]}).");

                    continue;
                }

                $seen[$key] = $line;

                $price = $this->normalisePrice($raw);
                $current = $this->currentRule($existing, $product->id, $group->id, $minQuantity);

                if ($current !== null && $this->scale((string) $current->unit_price) === $this->scale($price)) {
                    $plan->unchanged[] = ['sku' => $sku, 'group' => $code, 'price' => $price];
                    $rowPricedAnything = true;
                    $pricedProductIds[$product->id] = true;

                    continue;
                }

                if ($current !== null) {
                    // C-PRI-2.
                    $plan->supersedes[] = [
                        'id' => $current->id,
                        'sku' => $sku,
                        'group' => $code,
                        'from' => $current->effective_from->toDateString(),
                        'was' => (string) $current->unit_price,
                        'becomes' => $price,
                        'closesOn' => $effectiveFrom->copy()->subDay()->toDateString(),
                    ];
                }

                $plan->creates[] = [
                    'line' => $line,
                    'sku' => $sku,
                    'product' => $product->name,
                    'group' => $code,
                    'price' => $price,
                    'minQuantity' => $minQuantity,
                    'productId' => $product->id,
                    'priceGroupId' => $group->id,
                ];

                $rowPricedAnything = true;
                $pricedProductIds[$product->id] = true;
            }

            if (! $rowPricedAnything) {
                $plan->addWarning($line, $sku, 'The row carries no price in any tier.');
            }
        }

        // ⚠ The question the operator actually needs answered: what still
        // cannot be sold when this import finishes.
        foreach ($products as $product) {
            if ($product->is_active && ! isset($pricedProductIds[$product->id])) {
                $plan->stillUnpriced[] = $product->sku;
            }
        }

        sort($plan->stillUnpriced);

        return $plan;
    }

    /**
     * Whether the file row declares itself inactive. Same word list as the
     * catalogue importer; anything else (blank included) is NOT inactive, so
     * an unrecognised value still falls through to the unknown-SKU error.
     */
    private function rowMarkedInactive(array $values): bool
    {
        return in_array(strtolower(trim($values['is_active'] ?? '')), ['0', 'false', 'no', 'n', 'inactive'], true);
    }

    /**
     * ⚠ Quantity breaks arrive as REPEATED SKU ROWS with different
     * `min_quantity` values, which is why the duplicate check above keys on
     * quantity too. Absent column means the default tier, quantity zero.
     */
    private function minQuantity(array $values, PriceImportPlan $plan, int $line, string $sku): ?string
    {
        $raw = $values['min_quantity'] ?? '';

        if ($raw === '') {
            return '0';
        }

        if (! is_numeric($raw) || (float) $raw < 0) {
            $plan->addError($line, $sku, "min_quantity is '{$raw}'. It must be a number of zero or more.");

            return null;
        }

        return $this->normalisePrice($raw);
    }

    /**
     * @param  Collection<int, ProductPrice>  $existing
     */
    private function currentRule($existing, int $productId, int $groupId, string $minQuantity): ?ProductPrice
    {
        return $existing->first(fn (ProductPrice $rule) => $rule->product_id === $productId
            && $rule->price_group_id === $groupId
            && $rule->customer_id === null
            && $this->scale((string) $rule->min_quantity) === $this->scale($minQuantity));
    }

    private function normalisePrice(string $value): string
    {
        return number_format((float) $value, 4, '.', '');
    }

    /**
     * ⚠ Integer-scaled before comparison. `decimal:4` hands back a STRING from
     * MySQL and a float-ish value from SQLite, so `'190.00' === '190.0000'` is
     * false in one driver and the row would be rewritten every single run.
     */
    private function scale(string $value): int
    {
        return (int) round((float) $value * 10000);
    }
}
