<?php

namespace App\Modules\Commerce\Support;

use App\Modules\Commerce\Models\Store;
use App\Modules\Commerce\Models\StoreProduct;
use App\Modules\Inventory\Models\Product;

/**
 * What stops a product going into a basket on this shop, said in one place.
 *
 * ⭐ THE SAME THREE QUESTIONS THE PRODUCT PAGE ASKS, in the same order, through
 * the same two classes (`CataloguePublishing` for price, `Availability` for
 * stock policy). The page decides whether to show a button; this decides
 * whether the button works; a line in a basket is re-asked on every read. If
 * these were three copies they would drift, and the shape of the drift is a
 * customer told a thing is available and then refused.
 *
 * ⚠ Every answer is a sentence a customer can be shown. Nothing here throws:
 * LaLaDia's checkout answered a server error for a basket it simply could not
 * sell, which is a correct refusal reaching a customer as a broken shop.
 */
final class CartRules
{
    /** ⚠ A sane ceiling. A website sells jars, not pallets. */
    public const MAX_QUANTITY = 99;

    public static function refusalFor(Store $store, Product $product): ?string
    {
        $published = StoreProduct::query()
            ->where('store_id', $store->id)
            ->where('product_id', $product->id)
            ->whereNotNull('published_at')
            ->exists();

        if (! $published) {
            return 'This product is not available on this shop.';
        }

        // ⚠ The publish gate checked this once. A price can expire afterwards,
        // and a basket must not carry a thing nobody can say the cost of.
        if (! CataloguePublishing::hasPrice($store, $product)) {
            return 'This product has no price in force right now, so it cannot be added.';
        }

        $decision = Availability::decide($store, $product);

        if (! $decision->sellable) {
            return $decision->reason ?? 'This product cannot be sold right now.';
        }

        return null;
    }
}
