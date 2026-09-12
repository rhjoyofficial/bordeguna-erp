<?php

namespace App\Modules\Commerce\Support;

use App\Modules\Commerce\Models\Store;
use App\Modules\Commerce\Models\StoreProduct;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Actions\ResolveSellingPrice;
use App\Modules\Sales\Models\PriceGroup;
use Illuminate\Support\Carbon;

/**
 * What has to be true before a product may appear on a shop.
 *
 * ⭐⭐ ONE REFUSAL, AND IT IS ABOUT MONEY. A product published with no price in
 * force for that store's group does not error: it renders a page with a blank
 * where the price goes, or worse a zero, and the first person to find out is a
 * customer. Slice 1.4 taught the same lesson about sections a theme cannot draw
 * - a valid thing that renders as nothing is exactly what a publish gate is for.
 *
 * ⚠ The gate reads the SAME price list the counter reads, at the store's own
 * group. It does not keep its own copy and it does not decide a price; it only
 * asks whether one exists. Anything else would be a second opinion about money.
 *
 * ⚠ It deliberately does NOT check stock. Availability on these stores is
 * status-based (ADR-004) because the goods are made to order, so an item at zero
 * is still sellable and refusing to publish it would be wrong.
 */
final class CataloguePublishing
{
    /**
     * Why this product cannot go on sale on this store, or null if it can.
     */
    public static function refusalFor(Store $store, Product $product, ?Carbon $on = null): ?string
    {
        if (! $product->is_active) {
            return 'This product is switched off in the catalogue. Turn it back on before publishing it to a shop.';
        }

        if (! self::hasPrice($store, $product, $on)) {
            $group = $store->priceGroup?->name ?? 'Retail';

            return "No price is in force for this product in the {$group} price group, so the page would show a blank where the price goes. Set a selling price first.";
        }

        return null;
    }

    /**
     * ⚠ Mirrors the resolver's own specificity: a price for THIS group, or a
     * price with no group at all, which is the default every group falls back
     * to. A customer-specific price is not consulted - a website has no
     * customer, which is the whole content of ADR-005.
     */
    public static function hasPrice(Store $store, Product $product, ?Carbon $on = null): bool
    {
        return self::priceFor($store, $product, $on) !== null;
    }

    /**
     * What a visitor to this shop is quoted for this product, or null if
     * nothing is in force.
     *
     * ⭐⭐ THE STOREFRONT AND THE PUBLISH GATE ASK THE SAME QUESTION THROUGH THIS
     * ONE METHOD. Written twice they would come to disagree, and the shape of
     * that disagreement is the worst one available: a gate that says a product
     * is fine to publish and a page that then shows no price.
     *
     * ⭐⭐ AND IT DELEGATES TO THE ONE PRICING ENGINE, and it did not always.
     *
     * It used to run its own query - group rules before default ones, ordered by
     * `min_quantity` ASC, take the first - which reads like the same rule and is
     * not. `ResolveSellingPrice` takes the HIGHEST quantity break a line
     * qualifies for; this took the LOWEST, so it ignored quantity breaks
     * entirely. Measured on production: 5 of 52 price rules carry one.
     *
     * ⚠⚠ THE CONSEQUENCE WAS REAL AND ABOUT TO BECOME A TRANSACTION. A basket of
     * five would have quoted the single-unit price while the sales order the
     * checkout creates records the engine's answer - the shop showing one number
     * and billing another, on exactly the products a shop sets a break for. The
     * docblock above this class already said a second opinion about money was
     * unacceptable; the implementation had quietly become one.
     *
     * ⚠ Quantity defaults to 1, which is what the publish gate asks: "is there
     * any price in force at all". Measured on production before changing this:
     * every one of the 46 priced products has a rule applying at quantity 1, so
     * no product's publishability changes.
     */
    public static function priceFor(Store $store, Product $product, ?Carbon $on = null, string $quantity = '1'): ?string
    {
        return app(ResolveSellingPrice::class)->find(
            product: $product,
            customer: null,
            quantity: $quantity,
            on: $on ?? Carbon::now(),
            // ⭐ A website IS a price group (ADR-005). With no customer known,
            // the store's own group is what the engine prices against.
            priceGroupId: $store->price_group_id,
        )?->unitPrice;
    }

    /**
     * A URL-safe slug for a product on a store, unique within that store.
     *
     * ⚠ Suffixed rather than refused when it collides. Two shops in this group
     * genuinely sell "Mustard Oil 1L", and inside one shop two products can have
     * names that reduce to the same slug - which is a fact about the catalogue,
     * not a mistake the person publishing should have to solve.
     */
    public static function slugFor(Store $store, string $name, ?int $ignoreId = null): string
    {
        $base = trim(preg_replace('/-+/', '-', preg_replace('/[^a-z0-9]+/', '-', strtolower($name))) ?? '', '-');
        $base = $base === '' ? 'product' : $base;

        $slug = $base;
        $suffix = 2;

        while (self::slugTaken($store, $slug, $ignoreId)) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    private static function slugTaken(Store $store, string $slug, ?int $ignoreId): bool
    {
        return StoreProduct::query()
            ->where('store_id', $store->id)
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();
    }

    /**
     * The group a store prices at, spelled out for a screen.
     *
     * ⚠ Null is RETAIL, not "unset", and this says so rather than showing a
     * blank that somebody would read as a misconfiguration.
     */
    public static function priceGroupLabel(Store $store): string
    {
        return $store->priceGroup?->name
            ?? (PriceGroup::query()->where('code', PriceGroup::DEFAULT_CODE)->value('name') ?? 'Retail').' (default)';
    }
}
