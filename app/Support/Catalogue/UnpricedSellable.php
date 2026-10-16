<?php

namespace App\Support\Catalogue;

use App\Modules\Inventory\Enums\ProductType;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Models\ProductPrice;

/**
 * Sellable products with no selling price in force: each one refuses its first
 * sale. Same query as cutover:preflight's Selling prices check, so the classic
 * dashboard's banner, the Needs attention row (round 3, 16 Oct 2026) and the
 * preflight can never disagree.
 *
 * Reads the current business through the models' business scope.
 */
final class UnpricedSellable
{
    /** @return array{unpriced: int, sellable: int} */
    public static function count(): array
    {
        $sellable = Product::where('is_active', true)
            ->where('type', ProductType::FinishedGood)
            ->count();

        if ($sellable === 0) {
            return ['unpriced' => 0, 'sellable' => 0];
        }

        $priced = ProductPrice::query()
            ->inForceOn(now())
            ->distinct()
            ->count('product_id');

        return ['unpriced' => max(0, $sellable - $priced), 'sellable' => $sellable];
    }
}
