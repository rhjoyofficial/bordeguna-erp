<?php

namespace App\Modules\Commerce\Support;

use App\Modules\Commerce\Models\Cart;
use App\Modules\Commerce\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * Which basket this browser is holding on this shop.
 *
 * ⭐ A COOKIE CARRYING A TOKEN, NOT THE SESSION. The session cookie lives two
 * hours by default; a basket somebody means to come back to has to outlive
 * that, and a basket that vanished between two visits is precisely the one the
 * recovery screens exist to chase. Thirty days is the cookie's life.
 *
 * ⚠ The cookie is named after the STORE. Two shops in this group are two
 * hostnames, so the browser already keeps their cookies apart; the name is
 * there so a developer reading a cookie jar can tell which basket is whose,
 * and so a hostname shared in development does not hand Bionic's honey to
 * LaLaDia's checkout.
 */
final class CurrentCart
{
    public const COOKIE_MINUTES = 60 * 24 * 30;

    public static function cookieName(Store $store): string
    {
        return $store->key.'_cart';
    }

    /**
     * The open basket this request's cookie names, or null. ⚠ Never creates
     * one: opening /cart with nothing in it must not write a row per visitor.
     */
    public static function find(Request $request, Store $store): ?Cart
    {
        $token = $request->cookie(self::cookieName($store));

        if (! is_string($token) || $token === '') {
            return null;
        }

        return Cart::query()
            ->where('store_id', $store->id)
            ->where('token', $token)
            ->whereNull('converted_at')
            ->first();
    }

    /**
     * The open basket, created if there is none, with the cookie queued on the
     * response so the next request finds it again.
     */
    public static function open(Request $request, Store $store): Cart
    {
        $cart = self::find($request, $store);

        if ($cart !== null) {
            return $cart;
        }

        $cart = Cart::create([
            'store_id' => $store->id,
            'token' => Cart::newToken(),
        ]);

        // ⚠ httpOnly: nothing on the page needs to read the token, and a script
        // that can read it can carry it off. SameSite lax so the basket survives
        // a customer arriving from an ad link, which is how most of them arrive.
        Cookie::queue(cookie(
            name: self::cookieName($store),
            value: $cart->token,
            minutes: self::COOKIE_MINUTES,
            path: '/',
            httpOnly: true,
            sameSite: 'lax',
        ));

        return $cart;
    }
}
