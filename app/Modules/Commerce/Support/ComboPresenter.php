<?php

namespace App\Modules\Commerce\Support;

use App\Modules\Accounting\Support\Money;
use App\Modules\Commerce\Models\Store;
use App\Modules\Commerce\Models\StoreCombo;
use App\Modules\Commerce\Models\StoreProduct;
use App\Modules\Foundation\Support\DocumentMoney;

/**
 * A bundle, priced now, ready for a theme to draw. Slice 2.5a.
 *
 * ⚠ Every figure comes from `ComboPricing`, the class the checkout asks, so the
 * bundle page, the basket and the order cannot state three different prices.
 * The template shows what it is handed and never adds anything up.
 */
final class ComboPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function present(Store $store, StoreCombo $combo, int $quantity = 1): array
    {
        $combo->loadMissing('items.product', 'image');

        $quote = $combo->isPublished() && $combo->store_id === $store->id
            ? ComboPricing::quote($store, $combo, $quantity)
            : ComboQuote::refused('This bundle is not available on this shop.');

        // ⚠ What the shop calls each part, when it publishes that product; the
        // catalogue's own name otherwise. A part need not have a page of its own.
        $titles = StoreProduct::query()
            ->where('store_id', $store->id)
            ->whereIn('product_id', $combo->items->pluck('product_id'))
            ->get()
            ->keyBy('product_id');

        return [
            'id' => $combo->id,
            'title' => $combo->title,
            'summary' => $combo->summary,
            'slug' => $combo->slug,
            'url' => '/combos/'.$combo->slug,
            'image' => $combo->image?->toReference(),
            'parts' => $combo->items->map(fn ($item) => [
                'title' => $titles->get($item->product_id)?->displayTitle() ?? (string) $item->product?->name,
                'quantity' => $item->quantity,
            ])->values()->all(),
            // ⚠ Null when it cannot be sold. A bundle that cannot be priced is
            // absent, never zero - the rule a product page already follows.
            'price' => $quote->sellable() ? DocumentMoney::amount($quote->total->toTaka()) : null,
            // ⚠ The saving is stated only when there is one. "Save BDT 0" is
            // a claim the shop is not making.
            'parts_total' => $quote->sellable() && ! $quote->saving()->isZero()
                ? DocumentMoney::amount($quote->partsTotal->toTaka())
                : null,
            'saving' => $quote->sellable() && ! $quote->saving()->isZero()
                ? DocumentMoney::amount($quote->saving()->toTaka())
                : null,
            'availability' => $quote->availability(),
        ];
    }

    /**
     * ⚠ Per bundle, only when the total divides into whole paisa. A quantity
     * break on a part can make three bundles cost less than three times one,
     * and printing "each" beside a total it does not multiply to is a figure
     * somebody checks with a pencil and stops trusting.
     */
    public static function each(Money $total, int $quantity): ?string
    {
        if ($quantity < 1 || $total->minor % $quantity !== 0) {
            return null;
        }

        return DocumentMoney::amount(Money::ofMinor(intdiv($total->minor, $quantity))->toTaka());
    }
}
