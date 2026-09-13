<?php

namespace App\Modules\Commerce\Support;

use App\Modules\Accounting\Support\Money;
use App\Modules\Commerce\Models\Store;
use App\Modules\Commerce\Models\StoreCombo;
use App\Modules\Commerce\Models\StoreComboItem;

/**
 * What a bundle costs on this shop, and how its saving is shared across its
 * parts. Website Phase 2, slice 2.5a.
 *
 * ⭐⭐ ONE CLASS, ASKED BY EVERY READER: the admin screen, the bundle page, the
 * basket and the checkout. It is the combo's `CataloguePublishing::priceFor`,
 * and it exists for the same reason - written twice, the page and the order
 * come to disagree, and the shape of that disagreement is a customer quoted one
 * figure and billed another.
 *
 * ⭐⭐ EVERY PART IS PRICED BY THE PRICE LIST, NEVER BY THE BUNDLE. Each jar is
 * asked of the one pricing engine at the store's group and at the quantity the
 * bundle actually puts on the order line, so a 5+ break applies exactly as the
 * order will apply it. A custom bundle price then changes nothing about what a
 * jar costs: the difference is a DISCOUNT, shared across the parts in proportion
 * to their value (Joy, 13 September), so each product's revenue is stated net
 * of its own share.
 *
 * ⛔ A bundle priced ABOVE its parts is refused, never charged. Selling two jars
 * for more than the two jars cost separately is a mark-up wearing a bundle's
 * name, and a price list correction can create one silently - so it is
 * recomputed on every read rather than checked once when the price was typed.
 *
 * ⚠ It never throws. Every refusal is a sentence a customer or an admin can be
 * shown, for the reason `CartRules` gives.
 */
final class ComboPricing
{
    public static function quote(Store $store, StoreCombo $combo, int $quantity = 1): ComboQuote
    {
        $combo->loadMissing('items.product');

        /** @var list<StoreComboItem> $items */
        $items = $combo->items->all();

        if ($items === []) {
            return ComboQuote::refused('This bundle has nothing in it yet.');
        }

        $parts = [];

        foreach ($items as $item) {
            $product = $item->product;

            if ($product === null) {
                return ComboQuote::refused('Something in this bundle no longer exists.');
            }

            // ⚠ The same two questions a product on its own is asked, through
            // the same two classes. A bundle is exactly as sellable as its least
            // sellable part.
            if ($refusal = CataloguePublishing::refusalFor($store, $product)) {
                return ComboQuote::refused($product->name.': '.$refusal);
            }

            $decision = Availability::decide($store, $product);

            if (! $decision->sellable) {
                return ComboQuote::refused($product->name.': '.($decision->reason ?? 'cannot be sold right now.'));
            }

            $partQuantity = $item->quantity * $quantity;

            // ⚠⚠ Priced at THE QUANTITY THE ORDER LINE WILL CARRY, which is what
            // `CreateSalesOrder` resolves at. Pricing a part at one while the
            // order prices it at six is the defect `CartPresenter` records.
            $unitPrice = CataloguePublishing::priceFor($store, $product, null, (string) $partQuantity);

            if ($unitPrice === null) {
                return ComboQuote::refused($product->name.': no price is in force right now.');
            }

            $parts[] = new ComboPart(
                product: $product,
                quantity: $partQuantity,
                unitPrice: $unitPrice,
                gross: Money::ofProduct((string) $partQuantity, $unitPrice),
                discount: Money::zero(),
                leadTimeDays: $decision->leadTimeDays,
                madeToOrder: $decision->madeToOrder,
            );
        }

        $partsTotal = Money::sum(array_map(fn (ComboPart $part) => $part->gross, $parts));

        // ⚠ No custom price: the bundle is its parts, and nothing is discounted.
        if ($combo->price === null) {
            return new ComboQuote(null, $parts, $partsTotal, $partsTotal);
        }

        $total = Money::ofProduct((string) $quantity, (string) $combo->price);

        if ($total->minor > $partsTotal->minor) {
            return ComboQuote::refused(sprintf(
                'This bundle is priced at BDT %s, which is more than its contents cost bought separately (BDT %s). It cannot be sold until its price is corrected.',
                Money::ofProduct('1', (string) $combo->price)->format(),
                // ⚠ Per ONE bundle, whatever quantity was asked, so the sentence
                // compares like with like.
                Money::ofMinor(intdiv($partsTotal->minor, max(1, $quantity)))->format(),
            ));
        }

        $shares = self::share(Money::ofMinor($partsTotal->minor - $total->minor), $parts);

        foreach ($parts as $index => $part) {
            $parts[$index] = $part->withDiscount($shares[$index]);
        }

        return new ComboQuote(null, $parts, $partsTotal, $total);
    }

    /**
     * The saving, shared across the parts in proportion to their value.
     *
     * ⚠⚠ IN INTEGER PAISA, and the shares ALWAYS SUM TO THE SAVING EXACTLY.
     * Each part is rounded, the rounding left over goes to the most valuable
     * part, and no part's share may exceed that part's own value - a discount
     * larger than its line is a refund, which `CreateSalesOrder` refuses.
     *
     * @param  list<ComboPart>  $parts
     * @return list<Money>
     */
    private static function share(Money $saving, array $parts): array
    {
        $total = array_sum(array_map(fn (ComboPart $part) => $part->gross->minor, $parts));

        if ($saving->minor === 0 || $total === 0) {
            return array_map(fn () => Money::zero(), $parts);
        }

        $shares = [];

        foreach ($parts as $part) {
            $product = $saving->minor * $part->gross->minor;
            $share = intdiv($product, $total);

            if (($product % $total) * 2 >= $total) {
                $share++;
            }

            $shares[] = min($share, $part->gross->minor);
        }

        // Most valuable first, so the leftover lands where it is smallest in
        // proportion, and a cap on one part spills onto the next.
        $order = array_keys($parts);
        usort($order, fn (int $a, int $b) => $parts[$b]->gross->minor <=> $parts[$a]->gross->minor);

        $left = $saving->minor - array_sum($shares);

        foreach ($order as $index) {
            if ($left === 0) {
                break;
            }

            $room = $left > 0
                ? $parts[$index]->gross->minor - $shares[$index]
                : -$shares[$index];

            $move = $left > 0 ? min($left, $room) : max($left, $room);
            $shares[$index] += $move;
            $left -= $move;
        }

        return array_map(fn (int $minor) => Money::ofMinor($minor), $shares);
    }
}
