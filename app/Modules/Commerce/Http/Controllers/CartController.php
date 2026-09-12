<?php

namespace App\Modules\Commerce\Http\Controllers;

use App\Modules\Commerce\Http\Middleware\ResolveStorefront;
use App\Modules\Commerce\Models\CartLine;
use App\Modules\Commerce\Models\Store;
use App\Modules\Commerce\Support\CartPresenter;
use App\Modules\Commerce\Support\CartRules;
use App\Modules\Commerce\Support\CurrentCart;
use App\Modules\Commerce\Support\StorefrontSeo;
use App\Modules\Commerce\Themes\Theme;
use App\Modules\Commerce\Themes\ThemeRegistry;
use App\Modules\Inventory\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The basket on a shop. Website Phase 2, slice 2.1.
 *
 * ⛔ No checkout, no address, no payment and no stock movement of any kind.
 * Those are slice 2.4, and under the status-based policy a sale at zero
 * records a commitment when the ORDER is placed - a commitment is an order
 * concept and does not belong to a basket.
 *
 * ⚠ Every refusal is a `ValidationException`: a form submission is sent back
 * to the page it came from with the sentence under the field, and a JSON
 * request gets 422 with the same sentence. Never a 500 for a basket the shop
 * simply cannot sell.
 *
 * ⚠ Plain HTML forms, no script required. This is a public page on a phone on
 * a poor connection; a basket that needs JavaScript to work is a basket some
 * customers cannot use.
 */
class CartController
{
    public function show(Request $request): View
    {
        $store = $this->store($request);
        $cart = CurrentCart::find($request, $store);

        return view('storefront.cart', [
            'store' => $store,
            'theme' => $this->theme($store),
            'cart' => CartPresenter::present($store, $cart),
            'seo' => [
                'title' => StorefrontSeo::title($store, null, 'Your basket'),
                'description' => null,
                'canonical' => StorefrontSeo::canonical($store, $request),
                // ⛔ A basket is never indexed, whatever the store's own answer.
                'robots' => 'noindex, nofollow',
                'schemas' => [],
            ],
        ]);
    }

    public function add(Request $request): RedirectResponse
    {
        $store = $this->store($request);

        $data = $request->validate([
            'product' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1', 'max:'.CartRules::MAX_QUANTITY],
        ]);

        // ⚠ Scoped by the tenant the middleware entered, so another business's
        // product id reads as "not found" rather than as somebody else's honey.
        $product = Product::query()->find($data['product']);

        if ($product === null) {
            $this->refuse('This product is not available on this shop.');
        }

        if ($refusal = CartRules::refusalFor($store, $product)) {
            $this->refuse($refusal);
        }

        $cart = CurrentCart::open($request, $store);

        /** @var CartLine $line */
        $line = $cart->lines()->firstOrNew(['product_id' => $product->id]);
        $quantity = (int) $line->quantity + (int) $data['quantity'];

        if ($quantity > CartRules::MAX_QUANTITY) {
            $this->refuse(sprintf('You can have at most %d of one product in a basket.', CartRules::MAX_QUANTITY), 'quantity');
        }

        $line->quantity = $quantity;
        $line->save();

        return redirect('/cart')->with('cart_notice', 'Added to your basket.');
    }

    public function update(Request $request, int $line): RedirectResponse
    {
        $store = $this->store($request);

        $data = $request->validate([
            // ⚠ Zero is allowed here and means remove: a customer typing 0 in
            // the quantity box has said what they want clearly enough.
            'quantity' => ['required', 'integer', 'min:0', 'max:'.CartRules::MAX_QUANTITY],
        ]);

        $row = $this->ownLine($request, $store, $line);

        if ((int) $data['quantity'] === 0) {
            $row->delete();
        } else {
            $row->update(['quantity' => (int) $data['quantity']]);
        }

        return redirect('/cart');
    }

    public function remove(Request $request, int $line): RedirectResponse
    {
        $store = $this->store($request);

        $this->ownLine($request, $store, $line)->delete();

        return redirect('/cart');
    }

    /**
     * A line in THIS browser's basket on THIS shop, or 404. ⛔ The id alone is
     * never enough: a line number is guessable and a basket is somebody's.
     */
    private function ownLine(Request $request, Store $store, int $lineId): CartLine
    {
        $cart = CurrentCart::find($request, $store);

        abort_if($cart === null, 404);

        /** @var CartLine|null $row */
        $row = $cart->lines()->whereKey($lineId)->first();

        abort_if($row === null, 404);

        return $row;
    }

    private function refuse(string $reason, string $field = 'product'): never
    {
        throw ValidationException::withMessages([$field => $reason]);
    }

    /**
     * ⚠ The same two helpers `StorefrontController` uses, for the same reasons
     * written there: the middleware already decided which shop this is, and a
     * shop with no registered theme is honestly not ready rather than dressed
     * in somebody else's design.
     */
    private function store(Request $request): Store
    {
        $store = ResolveStorefront::current($request);

        abort_if($store === null, 404);

        return $store;
    }

    private function theme(Store $store): Theme
    {
        $registry = app(ThemeRegistry::class);

        abort_if($store->theme_key === null || ! $registry->has($store->theme_key), 503);

        return $registry->get($store->theme_key);
    }
}
