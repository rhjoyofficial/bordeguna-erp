<?php

namespace App\Modules\Sales\Support;

use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Actions\ResolveSellingPrice;
use App\Modules\Sales\Models\Customer;
use Illuminate\Support\Carbon;

/**
 * ⭐ The CAP REFERENCE PRICE of a product (ChatGPT ruling Q3, Phase 2,
 * 17 Oct 2026): the figure the staff monthly allowance (Tk 3,000) is measured
 * at, frozen on every request line.
 *
 *  - a product with a printed MRP: the MRP, labelled `mrp`;
 *  - a product without one (market-priced goods): the CURRENT selling price
 *    at request time, labelled `reference` and never called MRP;
 *  - nothing in force: null, and the staff benefit refuses the product.
 *
 * Plan G7: builder G's `products.pricing_mode` decides between the two when
 * it lands; until then "no MRP" is the market case. One call site (the
 * staff-purchase flow) so the rule changes in one place.
 */
final class ProductPricing
{
    public const LABEL_MRP = 'mrp';

    public const LABEL_REFERENCE = 'reference';

    /**
     * @return array{amount: string, label: string}|null amount in taka (2dp)
     */
    public static function capReferencePrice(Product $product, ?Carbon $on = null): ?array
    {
        if ((float) ($product->mrp ?? 0) > 0) {
            return ['amount' => number_format((float) $product->mrp, 2, '.', ''), 'label' => self::LABEL_MRP];
        }

        $walkIn = Customer::walkIn();

        if ($walkIn === null) {
            return null;
        }

        $resolved = app(ResolveSellingPrice::class)->find($product, $walkIn, '1', $on ?? Carbon::now());

        if ($resolved === null) {
            return null;
        }

        return ['amount' => number_format((float) $resolved->unitPrice, 2, '.', ''), 'label' => self::LABEL_REFERENCE];
    }
}
