{{--
    The basket. Website Phase 2, slice 2.1.

    ⚠ Every figure on this page was resolved from the price list a moment ago
    by CartPresenter. The template shows what it was handed; it never multiplies.

    ⚠ The checkout button appeared in slice 2.4 and is GATED: it is absent until
    the shop can actually take this order - a district chosen, a delivery rate
    that covers it, and nothing unsellable in the basket. A button that leads to
    a refusal is worse than no button, because the customer fills in an address
    first to find out.

    ⚠ A line the shop can no longer sell is listed with its reason and left out
    of the total. It is the customer's to remove; a basket that throws or that
    quietly drops a jar is worse than one that says what happened.
--}}
@extends('themes.'.$theme->key().'.layout')

@section('content')
    <section class="block">
        <div class="wrap">
            <h1>Your basket</h1>

            @if (session('cart_notice'))
                <p class="notice">{{ session('cart_notice') }}</p>
            @endif

            @if ($errors->any())
                <p class="error">{{ $errors->first() }}</p>
            @endif

            @if ($cart['lines'] === [])
                <p class="muted">Your basket is empty.</p>
                <p><a href="/">Back to the shop</a></p>
            @else
                <table class="basket">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Price</th>
                            <th>Quantity</th>
                            <th>Total</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach ($cart['lines'] as $line)
                        <tr @if ($line['problem']) class="problem" @endif>
                            <td>
                                @if ($line['url'])
                                    <a href="{{ $line['url'] }}">{{ $line['title'] }}</a>
                                @else
                                    {{ $line['title'] }}
                                @endif
                                @if ($line['problem'])
                                    <p class="muted">{{ $line['problem'] }}</p>
                                @elseif ($line['availability']['message'] ?? null)
                                    <p class="muted">{{ $line['availability']['message'] }}</p>
                                @endif
                            </td>
                            <td>@if ($line['unit_price'])BDT {{ $line['unit_price'] }}@else<span class="muted">&mdash;</span>@endif</td>
                            <td>
                                <form method="post" action="/cart/lines/{{ $line['id'] }}" class="inline">
                                    @csrf
                                    @method('PATCH')
                                    <input type="number" name="quantity" value="{{ $line['quantity'] }}" min="0" max="99" aria-label="Quantity">
                                    <button type="submit">Update</button>
                                </form>
                            </td>
                            <td>@if ($line['line_total'])BDT {{ $line['line_total'] }}@else<span class="muted">&mdash;</span>@endif</td>
                            <td>
                                <form method="post" action="/cart/lines/{{ $line['id'] }}" class="inline">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit">Remove</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <th colspan="3">Subtotal</th>
                            <th>BDT {{ $cart['subtotal'] }}</th>
                            <th></th>
                        </tr>
                        @if ($cart['delivery']['charge'])
                            <tr>
                                <th colspan="3">Delivery to {{ $cart['delivery']['district'] }}</th>
                                <th>BDT {{ $cart['delivery']['charge'] }}</th>
                                <th></th>
                            </tr>
                        @endif
                        @if ($cart['total'])
                            <tr>
                                <th colspan="3">Total</th>
                                <th>BDT {{ $cart['total'] }}</th>
                                <th></th>
                            </tr>
                        @endif
                    </tfoot>
                </table>

                @if ($cart['problems'] > 0)
                    <p class="muted">
                        {{ $cart['problems'] === 1 ? 'One item' : $cart['problems'].' items' }} in your basket cannot be sold right now and {{ $cart['problems'] === 1 ? 'is' : 'are' }} not counted in the subtotal.
                    </p>
                @endif

                {{-- Delivery, slice 2.3.

                     ⭐⭐ THE CUSTOMER PICKS A PLACE, NEVER A PRICE BAND. There is
                     no zone selector here and there never will be: LaLaDia asked
                     for both, compared them nowhere, and let somebody in Bagerhat
                     pay the Dhaka rate on every order for months.

                     ⚠ A plain form with no script, like the rest of this page.
                     A basket that needs JavaScript is one some customers on a
                     poor connection cannot use. --}}
                <form method="post" action="/cart/delivery" class="cart-delivery">
                    @csrf
                    <label for="delivery-district">Deliver to</label>
                    <select id="delivery-district" name="district" required>
                        <option value="">Choose your district</option>
                        @foreach ($districts as $district)
                            <option value="{{ $district['name'] }}" @selected($cart['delivery']['district'] === $district['name'])>
                                {{ $district['name'] }}@if ($district['bn_name']) &mdash; {{ $district['bn_name'] }}@endif
                            </option>
                        @endforeach
                    </select>
                    <button type="submit">Work out delivery</button>
                    @error('district')<span class="error">{{ $message }}</span>@enderror
                </form>

                @if ($cart['delivery']['cannot_quote'])
                    {{-- ⛔ Said plainly rather than shown as free. A shop with no
                         rate covering this district has not agreed to deliver
                         there for nothing. --}}
                    <p class="muted">We cannot work out delivery to {{ $cart['delivery']['district'] }} yet. Please contact us before ordering.</p>
                @elseif ($cart['delivery']['charge'])
                    <p class="muted">
                        {{ $cart['delivery']['zone'] }} &mdash; BDT {{ $cart['delivery']['charge'] }}.
                    </p>
                @else
                    <p class="muted">Choose your district to see what delivery costs.</p>
                @endif

                {{-- Checkout, slice 2.4.

                     ⚠⚠ GATED ON WHAT THE SERVER WILL ACTUALLY ACCEPT, and the
                     three conditions are the same three `PlaceWebsiteOrder`
                     refuses on. They are stated here as well so the reason is
                     visible on the page that can fix it, rather than after an
                     address has been typed. --}}
                @if ($cart['problems'] === 0 && $cart['delivery']['district'] && ! $cart['delivery']['cannot_quote'])
                    <p><a href="/checkout" class="button">Checkout</a></p>
                @endif

                <p><a href="/">Keep shopping</a></p>
            @endif
        </div>
    </section>
@endsection
