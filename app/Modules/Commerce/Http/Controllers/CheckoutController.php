<?php

namespace App\Modules\Commerce\Http\Controllers;

use App\Modules\Commerce\Actions\PlaceWebsiteOrder;
use App\Modules\Commerce\Actions\StartGatewayPayment;
use App\Modules\Commerce\Exceptions\GatewayUnavailable;
use App\Modules\Commerce\Http\Middleware\ResolveStorefront;
use App\Modules\Commerce\Models\Store;
use App\Modules\Commerce\Support\CartPresenter;
use App\Modules\Commerce\Support\CurrentCart;
use App\Modules\Commerce\Support\PlacedOrderPresenter;
use App\Modules\Commerce\Support\StorefrontSeo;
use App\Modules\Commerce\Themes\Theme;
use App\Modules\Commerce\Themes\ThemeRegistry;
use App\Modules\Sales\Enums\SalesChannel;
use App\Modules\Sales\Models\SalesOrder;
use App\Support\BdGeo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Checkout on a shop, and the order it produces. Website Phase 2, slice 2.4.
 *
 * ⚠⚠ THE ORDER IT WRITES IS A REAL ERP SALES ORDER - the same document the
 * counter and the phone produce, through the same `CreateSalesOrder`. There is
 * deliberately no website order table and no second commercial document: two
 * places recording what a customer agreed to buy is how a shop ends up unable
 * to say what it sold.
 *
 * ⚠ No stock moves and no journal posts. A sales order is a promise, and a web
 * sale under the status-based policy records exactly that.
 *
 * ⚠ Plain HTML forms, no script needed to place an order. The district cascade
 * is progressive: choosing a district reloads the page with its thanas, so a
 * customer on a poor connection with a cheap phone can still buy. Script makes
 * that smoother; its absence never makes it impossible.
 */
class CheckoutController
{
    public function show(Request $request): View|RedirectResponse
    {
        $store = $this->store($request);
        $cart = CurrentCart::find($request, $store);

        // ⚠ A basket that has already become an order is not an empty basket,
        // and saying so is the difference between "here is your order" and a
        // customer believing it vanished. See CurrentCart::findConverted.
        if ($cart === null && ($placed = $this->alreadyPlaced($request, $store)) !== null) {
            return $placed;
        }

        $presented = CartPresenter::present($store, $cart);

        // ⚠ The thanas of the district the BASKET holds, not of one posted to
        // this page. The delivery district is chosen on the basket and is the
        // single source of the rate, so the address form follows it rather than
        // offering a second, disagreeing answer. Slice 2.3's whole rule.
        $district = $cart?->delivery_district;

        return view('storefront.checkout', [
            'store' => $store,
            'theme' => $this->theme($store),
            'cart' => $presented,
            'districts' => BdGeo::districts(),
            'district' => $district,
            'thanas' => BdGeo::thanas($district),
            // ⚠ Areas are only offered once a thana is known, and an empty list
            // is a real answer for a thana the dataset has no areas for.
            'thana' => old('thana'),
            'areas' => BdGeo::areas($district, old('thana')),
            // ⭐ SSLCommerz slice P2c: offered only while the shop can actually
            // record an online payment, decided afresh on every view.
            'online_payment' => $store->takesOnlinePayment(),
            'seo' => [
                'title' => StorefrontSeo::title($store, null, 'Checkout'),
                'description' => null,
                'canonical' => StorefrontSeo::canonical($store, $request),
                // ⛔ Never indexed, whatever the store's own answer. A checkout
                // in a search result is a page a crawler fills in.
                'robots' => 'noindex, nofollow',
                'schemas' => [],
            ],
        ]);
    }

    /**
     * ⚠ Every refusal is a `ValidationException` raised inside the action, so
     * it comes back to this page with the sentence under the right field. A
     * basket the shop cannot sell is a refusal, never a 500 - LaLaDia answered
     * a server error for exactly that and it read as a broken shop.
     */
    public function place(Request $request, PlaceWebsiteOrder $orders, StartGatewayPayment $payments): RedirectResponse
    {
        $store = $this->store($request);

        // ⚠ Shape only. Everything that needs the shop's own knowledge - is
        // this a real thana, is there a price, can we quote delivery - is
        // decided by the action against the ERP, not by a rule string here.
        $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:190'],
            'thana' => ['required', 'string', 'max:100'],
            'area' => ['nullable', 'string', 'max:120'],
            'address_line' => ['required', 'string', 'max:500'],
            'landmark' => ['nullable', 'string', 'max:255'],
            'payment_method' => ['nullable', 'string', 'max:20'],
        ]);

        $cart = CurrentCart::find($request, $store);

        if ($cart === null) {
            // ⭐ The second press of a double-tapped button lands here, because
            // the basket it belonged to is converted and is therefore no longer
            // the current one. It must find the order rather than an empty page.
            return $this->alreadyPlaced($request, $store) ?? redirect('/cart');
        }

        $input = $request->only(['name', 'phone', 'email', 'thana', 'area', 'address_line', 'landmark', 'payment_method']);

        // ⚠⚠ THE DISTRICT COMES FROM THE BASKET AND IS NOT ACCEPTED FROM THIS
        // FORM. It is what the delivery charge was quoted from, so letting the
        // address form post a different one is the disagreeing pair slice 2.3
        // exists to make impossible - a customer charged the Dhaka rate for a
        // parcel going to Bagerhat.
        $input['district'] = $cart->delivery_district;

        $order = $orders->handle($store, $cart, $input);

        // ⭐ SSLCommerz slice P2c: straight to the payment page. The order is
        // already saved, unpaid and out of the approval queue, so a customer who
        // walks away from the payment page leaves nothing for anybody to confirm.
        // ⚠ If the page cannot be opened the order stays, and its page says so
        // and offers to try again.
        if ($order->payment_method === PlaceWebsiteOrder::ONLINE) {
            try {
                return redirect()->away($payments->handle($order, $store, OnlinePaymentController::callbackUrls($request))['url']);
            } catch (GatewayUnavailable $e) {
                return redirect('/orders/'.$order->shop_reference)->with('payment_problem', $e->getMessage());
            }
        }

        return redirect('/orders/'.$order->shop_reference);
    }

    /**
     * The order somebody has just placed, or comes back to look at.
     *
     * ⚠⚠ THE REFERENCE IS THE KEY, and it is a random tail rather than a count
     * precisely so that this page cannot be walked. ⛔ It is also scoped to THIS
     * shop and to website orders only: an internal order number must not be
     * readable from a public page by guessing, and one shop must never render
     * another's order even within the same group.
     *
     * ⚠ It states what has happened and no more. For cash on delivery there is
     * no payment to report; for an order paid online, only what the gateway has
     * PROVEN (SSLCommerz slice P2c). A page that implied payment on less is how
     * LaLaDia came to fire a full-value Purchase for an order nobody had paid for.
     */
    public function order(Request $request, string $reference): View
    {
        $store = $this->store($request);

        /** @var SalesOrder|null $order */
        $order = SalesOrder::query()
            ->with(['lines.product', 'address', 'approvalRequest'])
            ->where('store_id', $store->getKey())
            ->where('channel', SalesChannel::Website)
            ->where('shop_reference', $reference)
            ->first();

        abort_if($order === null, 404);

        return view('storefront.order', [
            'store' => $store,
            'theme' => $this->theme($store),
            'order' => PlacedOrderPresenter::present($order),
            'seo' => [
                'title' => StorefrontSeo::title($store, null, 'Order '.$order->shop_reference),
                'description' => null,
                'canonical' => StorefrontSeo::canonical($store, $request),
                'robots' => 'noindex, nofollow',
                'schemas' => [],
            ],
        ]);
    }

    /**
     * The order this browser's basket already became, or null.
     *
     * ⚠⚠ TWO GUARDS, AND BOTH ARE NEEDED. `PlaceWebsiteOrder` refuses to write
     * a twin for a cart that already has an order, which protects any caller;
     * this one is what makes that reachable through a browser at all, because a
     * converted cart is deliberately not the current cart. A guard nothing can
     * reach is not a guard - it was found by a test that pressed the button
     * twice and landed on an empty basket.
     */
    private function alreadyPlaced(Request $request, Store $store): ?RedirectResponse
    {
        $converted = CurrentCart::findConverted($request, $store);
        $reference = $converted?->order?->shop_reference;

        return $reference === null ? null : redirect('/orders/'.$reference);
    }

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
