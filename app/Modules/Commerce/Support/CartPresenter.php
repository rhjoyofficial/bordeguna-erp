<?php

namespace App\Modules\Commerce\Support;

use App\Modules\Commerce\Models\Cart;
use App\Modules\Commerce\Models\CartLine;
use App\Modules\Commerce\Models\Store;
use App\Modules\Commerce\Models\StoreProduct;
use App\Modules\Foundation\Support\DocumentMoney;

/**
 * A basket, priced now, ready for a theme to draw.
 *
 * ⭐⭐ PRICES ARE RESOLVED HERE, AT READ TIME, EVERY TIME. The rows hold a
 * product and a quantity; what those cost is asked of the price list each time
 * the basket is looked at, through the one resolver the shop already uses. So
 * a price corrected in the ERP corrects the basket on its next read, and a
 * basket cannot hold a figure the price list disagrees with.
 *
 * ⚠ A line that has become unsellable - taken off sale, price expired, now
 * strict at zero - is SHOWN WITH ITS REASON, not thrown and not silently
 * dropped. The customer decides what to do with it; the total simply does not
 * include it. LaLaDia once threw a 500 on exactly this, so a customer whose
 * jar sold out could not open their basket to remove it.
 */
final class CartPresenter
{
    /**
     * @return array{lines: list<array<string,mixed>>, count: int, subtotal: ?string, problems: int, delivery: array<string,mixed>, total: ?string}
     */
    public static function present(Store $store, ?Cart $cart): array
    {
        if ($cart === null) {
            // ⚠ The same SHAPE as a real basket, so a theme has one set of keys
            // to draw rather than two. An empty basket is not a special case.
            return [
                'lines' => [], 'count' => 0, 'subtotal' => null, 'problems' => 0,
                'delivery' => [
                    'district' => null, 'zone' => null, 'charge' => null,
                    'is_fallback' => false, 'cannot_quote' => false,
                ],
                'total' => null,
            ];
        }

        $lines = $cart->lines()->with('product')->orderBy('id')->get();

        $rows = StoreProduct::query()
            ->with('image')
            ->where('store_id', $store->id)
            ->whereIn('product_id', $lines->pluck('product_id'))
            ->get()
            ->keyBy('product_id');

        $subtotal = '0';
        $count = 0;
        $problems = 0;
        $presented = [];

        foreach ($lines as $line) {
            /** @var CartLine $line */
            $product = $line->product;
            $row = $rows->get($line->product_id);

            // ⚠⚠ PRICED AT THE QUANTITY ACTUALLY IN THE BASKET, not at one. A
            // shop that sets a 5+ rate means it, and asking for the single-unit
            // price would quote a customer more than the sales order the
            // checkout then writes - the shop showing one number and billing
            // another on exactly the products it chose to discount.
            $price = $product === null
                ? null
                : CataloguePublishing::priceFor($store, $product, null, (string) $line->quantity);
            $problem = $product === null ? 'This product no longer exists.' : CartRules::refusalFor($store, $product);

            $lineTotal = ($price === null || $problem !== null)
                ? null
                : bcmul($price, (string) $line->quantity, 2);

            if ($lineTotal !== null) {
                $subtotal = bcadd($subtotal, $lineTotal, 2);
            } else {
                $problems++;
            }

            $count += $line->quantity;

            $presented[] = [
                'kind' => 'product',
                'id' => $line->id,
                'product_id' => $line->product_id,
                // ⚠ Where this line's own quantity and remove forms post. A
                // bundle line posts somewhere else, so the template must not
                // build the address itself.
                'action' => '/cart/lines/'.$line->id,
                'title' => $row?->displayTitle() ?? (string) $product?->name,
                'url' => $row === null ? null : '/products/'.$row->slug,
                'image' => $row?->image?->toReference(),
                'quantity' => $line->quantity,
                'unit_price' => $price === null ? null : DocumentMoney::amount($price),
                'line_total' => $lineTotal === null ? null : DocumentMoney::amount($lineTotal),
                'parts' => [],
                'saving' => null,
                'problem' => $problem,
                'availability' => $product === null ? null : Availability::decide($store, $product)->toArray(),
            ];
        }

        // ⭐ Bundles, slice 2.5a. Priced through `ComboPricing` at the quantity in
        // the basket, which is what the checkout will price them at - never the
        // single-bundle price multiplied, because a part's quantity break can
        // make two bundles cost less than twice one.
        $comboLines = $cart->comboLines()->with('combo.items.product', 'combo.image')->orderBy('id')->get();

        foreach ($comboLines as $line) {
            $combo = $line->combo;
            $quote = $combo === null ? null : ComboPricing::quote($store, $combo, $line->quantity);
            $problem = $combo === null
                ? 'This bundle no longer exists.'
                : CartRules::refusalForCombo($store, $combo, $line->quantity);

            $total = ($problem === null && $quote !== null) ? $quote->total : null;

            if ($total !== null) {
                $subtotal = bcadd($subtotal, $total->toTaka(), 2);
            } else {
                $problems++;
            }

            $count += $line->quantity;
            $presentedCombo = $combo === null ? null : ComboPresenter::present($store, $combo);

            $presented[] = [
                'kind' => 'combo',
                'id' => $line->id,
                'product_id' => null,
                'action' => '/cart/combos/'.$line->id,
                'title' => (string) $combo?->title,
                'url' => $combo === null ? null : '/combos/'.$combo->slug,
                'image' => $combo?->image?->toReference(),
                'quantity' => $line->quantity,
                'unit_price' => $total === null ? null : ComboPresenter::each($total, $line->quantity),
                'line_total' => $total === null ? null : DocumentMoney::amount($total->toTaka()),
                'parts' => $presentedCombo['parts'] ?? [],
                'saving' => ($total !== null && ! $quote->saving()->isZero())
                    ? DocumentMoney::amount($quote->saving()->toTaka())
                    : null,
                'problem' => $problem,
                'availability' => $quote?->availability(),
            ];
        }

        $delivery = DeliveryQuote::for($store, $cart->delivery_district);

        return [
            'lines' => $presented,
            'count' => $count,
            'subtotal' => $presented === [] ? null : DocumentMoney::amount($subtotal),
            'problems' => $problems,
            'delivery' => [
                'district' => $delivery->district,
                'zone' => $delivery->zoneName(),
                'charge' => $delivery->charge() === null ? null : DocumentMoney::amount($delivery->charge()),
                // ⚠ The theme needs to tell these apart: a rate set for this
                // district reads differently from the catch-all, and a shop that
                // cannot quote at all must say so rather than show nothing and
                // let the customer assume delivery is free.
                'is_fallback' => $delivery->isFallback,
                'cannot_quote' => $delivery->cannotQuote(),
            ],
            // ⚠ The total includes delivery only when there IS a quote. Adding
            // zero for an unquotable district would state a total the shop has
            // not agreed to honour.
            'total' => ($presented === [] || $delivery->charge() === null)
                ? null
                : DocumentMoney::amount(bcadd($subtotal, $delivery->charge(), 2)),
        ];
    }

    /**
     * How many things are in the basket, for the header. ⚠ One cheap sum, not
     * a full presentation, because the header is on every page.
     */
    public static function count(?Cart $cart): int
    {
        return $cart === null ? 0 : (int) $cart->lines()->sum('quantity') + (int) $cart->comboLines()->sum('quantity');
    }
}
