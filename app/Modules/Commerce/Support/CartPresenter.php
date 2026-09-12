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
     * @return array{lines: list<array<string,mixed>>, count: int, subtotal: ?string, problems: int}
     */
    public static function present(Store $store, ?Cart $cart): array
    {
        if ($cart === null) {
            return ['lines' => [], 'count' => 0, 'subtotal' => null, 'problems' => 0];
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

            $price = $product === null ? null : CataloguePublishing::priceFor($store, $product);
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
                'id' => $line->id,
                'product_id' => $line->product_id,
                'title' => $row?->displayTitle() ?? (string) $product?->name,
                'url' => $row === null ? null : '/products/'.$row->slug,
                'image' => $row?->image?->toReference(),
                'quantity' => $line->quantity,
                'unit_price' => $price === null ? null : DocumentMoney::amount($price),
                'line_total' => $lineTotal === null ? null : DocumentMoney::amount($lineTotal),
                'problem' => $problem,
                'availability' => $product === null ? null : Availability::decide($store, $product)->toArray(),
            ];
        }

        return [
            'lines' => $presented,
            'count' => $count,
            'subtotal' => $presented === [] ? null : DocumentMoney::amount($subtotal),
            'problems' => $problems,
        ];
    }

    /**
     * How many things are in the basket, for the header. ⚠ One cheap sum, not
     * a full presentation, because the header is on every page.
     */
    public static function count(?Cart $cart): int
    {
        return $cart === null ? 0 : (int) $cart->lines()->sum('quantity');
    }
}
