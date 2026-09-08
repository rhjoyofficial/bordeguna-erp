<?php

namespace App\Modules\Commerce\Support;

use App\Modules\Commerce\Models\Store;
use App\Modules\Commerce\Models\StoreProduct;
use App\Modules\Inventory\Models\Product;
use App\Modules\Sales\Models\PriceGroup;
use App\Modules\Sales\Models\ProductPrice;
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
        $on ??= Carbon::now();

        return ProductPrice::query()
            ->where('product_id', $product->id)
            ->whereNull('customer_id')
            ->where(function ($query) use ($store) {
                $query->whereNull('price_group_id');

                if ($store->price_group_id !== null) {
                    $query->orWhere('price_group_id', $store->price_group_id);
                }
            })
            ->where('effective_from', '<=', $on->toDateString())
            ->where(function ($query) use ($on) {
                $query->whereNull('effective_to')->orWhere('effective_to', '>=', $on->toDateString());
            })
            ->exists();
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
