{{--
    An order that has been placed. Website Phase 2, slice 2.4.

    ⚠⚠ EVERY FIGURE HERE IS THE AGREEMENT, NOT TODAY'S PRICE LIST. The basket
    asks what things cost now; an order says what was agreed then. A confirmation
    page that re-priced itself would show a customer a different total next week
    than the one they accepted.

    ⚠ It reports NO PAYMENT STATE, because there is none: cash on delivery means
    the money arrives with the parcel. LaLaDia's order-success page checked
    ownership rather than payment and fired a full-value Purchase for an order
    nobody had paid for - the two must never blur.

    ⚠ noindex, and the reference is a random tail rather than a count, so this
    page cannot be walked or found.
--}}
@extends('themes.'.$theme->key().'.layout')

@section('content')
    <section class="block">
        <div class="wrap">
            <h1>Thank you &mdash; your order is placed</h1>

            <p>
                Your order number is <strong>{{ $order['reference'] }}</strong>.
                Please keep it: it is what we will ask for if you contact us.
            </p>

            {{-- ⚠ Said plainly and early. An order somebody has to be telephoned
                 about is the normal case on these shops, not an exception, and a
                 page implying the parcel is already on its way is how a
                 confirmation call becomes a complaint. --}}
            <p class="muted">
                We will call you to confirm this order before it is dispatched.
                Nothing has been charged &mdash; you pay the rider when the parcel arrives.
            </p>

            <table class="basket">
                <thead>
                    <tr><th>Item</th><th>Quantity</th><th>Price</th><th>Total</th></tr>
                </thead>
                <tbody>
                @foreach ($order['lines'] as $line)
                    <tr>
                        <td>
                            {{ $line['title'] }}
                            @if ($line['bundle'])<p class="muted">{{ $line['bundle'] }}</p>@endif
                        </td>
                        <td>{{ $line['quantity'] }}</td>
                        <td>
                            BDT {{ $line['unit_price'] }}
                            {{-- ⚠ The agreed bundle saving, stated beside the price it
                                 came off rather than hidden inside a lower one. --}}
                            @if ($line['discount'])<p class="muted">less BDT {{ $line['discount'] }}</p>@endif
                        </td>
                        <td>BDT {{ $line['line_total'] }}</td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot>
                    <tr><th colspan="3">Goods</th><th>BDT {{ $order['goods_total'] }}</th></tr>
                    @if ($order['order_discount'])
                        <tr><th colspan="3">Code {{ $order['coupon_code'] }}</th><th>&minus; BDT {{ $order['order_discount'] }}</th></tr>
                    @endif
                    @if ($order['delivery_charge'] !== null)
                        <tr><th colspan="3">Delivery</th><th>BDT {{ $order['delivery_charge'] }}</th></tr>
                    @endif
                    <tr><th colspan="3">Total to pay on delivery</th><th>BDT {{ $order['total'] }}</th></tr>
                </tfoot>
            </table>

            @if ($order['recipient'])
                <h2>Delivering to</h2>
                <p>
                    {{ $order['recipient']['name'] }}<br>
                    {{ $order['recipient']['phone'] }}<br>
                    {{ $order['recipient']['address'] }}
                </p>
            @endif

            <p class="muted">Placed on {{ $order['placed_on'] }}.</p>

            <p><a href="/">Keep shopping</a></p>
        </div>
    </section>
@endsection
