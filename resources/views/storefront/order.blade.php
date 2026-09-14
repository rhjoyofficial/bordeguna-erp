{{--
    An order that has been placed. Website Phase 2, slice 2.4.

    ⚠⚠ EVERY FIGURE HERE IS THE AGREEMENT, NOT TODAY'S PRICE LIST. The basket
    asks what things cost now; an order says what was agreed then. A confirmation
    page that re-priced itself would show a customer a different total next week
    than the one they accepted.

    ⚠ For cash on delivery it reports NO PAYMENT STATE, because there is none:
    the money arrives with the parcel. For an order paid online (SSLCommerz slice
    P2c) it reports only what the gateway has PROVEN. LaLaDia's order-success
    page checked ownership rather than payment and fired a full-value Purchase
    for an order nobody had paid for - the two must never blur.

    ⚠ noindex, and the reference is a random tail rather than a count, so this
    page cannot be walked or found.
--}}
@extends('themes.'.$theme->key().'.layout')

@section('content')
    <section class="block">
        <div class="wrap">
            @php($payment = $order['payment'])

            @if ($payment && in_array($payment['state'], ['awaiting'], true))
                <h1>Your order is saved &mdash; it is not paid yet</h1>
            @elseif ($payment && in_array($payment['state'], ['cancelled', 'refund_due'], true))
                <h1>This order was cancelled</h1>
            @else
                <h1>Thank you &mdash; your order is placed</h1>
            @endif

            @if (session('payment_problem'))
                <p class="error">We could not open the payment page: {{ session('payment_problem') }}</p>
            @endif

            <p>
                Your order number is <strong>{{ $order['reference'] }}</strong>.
                Please keep it: it is what we will ask for if you contact us.
            </p>

            {{-- ⚠ Said plainly and early. An order somebody has to be telephoned
                 about is the normal case on these shops, not an exception, and a
                 page implying the parcel is already on its way is how a
                 confirmation call becomes a complaint. --}}
            @if ($payment === null)
                <p class="muted">
                    We will call you to confirm this order before it is dispatched.
                    Nothing has been charged &mdash; you pay the rider when the parcel arrives.
                </p>
            @elseif ($payment['state'] === 'paid')
                <p><strong>Paid online: BDT {{ $payment['amount_paid'] }}.</strong> The rider collects nothing.</p>
                <p class="muted">We will call you to confirm this order before it is dispatched.</p>
            @elseif ($payment['state'] === 'confirming')
                {{-- ⚠ The gateway has proven it; the shop's books have not caught up.
                     Said as received, never as a problem for the customer. --}}
                <p><strong>Your payment of BDT {{ $payment['amount_paid'] }} has been received.</strong> The rider collects nothing.</p>
                <p class="muted">We will call you to confirm this order before it is dispatched.</p>
            @elseif ($payment['state'] === 'refund_due')
                <p><strong>You paid BDT {{ $payment['amount_paid'] }} for it, and that money is yours.</strong> We will contact you to return it.</p>
            @elseif ($payment['state'] === 'cancelled')
                <p class="muted">Nothing was paid for it, and nothing will be sent.</p>
            @else
                @if ($payment['last_attempt'])
                    <p class="muted">Last try: {{ strtolower($payment['last_attempt']) }}. Nothing was charged.</p>
                @endif
                @if ($payment['can_pay'])
                    <form method="post" action="/orders/{{ $order['reference'] }}/pay">
                        @csrf
                        <p><button type="submit">Pay BDT {{ $order['total'] }} now</button></p>
                    </form>
                    <p class="muted">Unpaid orders are cancelled after {{ $payment['pay_by'] }}.</p>
                @else
                    <p class="muted">Paying online is not available right now. Please contact us and quote your order number.</p>
                @endif
            @endif

            {{-- ⭐ D24: the window this order was PROMISED (frozen on the order), and the
                 date it became once somebody confirmed it. Before confirmation there is
                 no date to state, and the page does not invent one. --}}
            @if ($order['promise'])
                <p class="muted">
                    {{ $order['promise'] }}
                    @if ($order['expected_by'])Confirmed: expect it by {{ $order['expected_by'] }}.@endif
                </p>
            @endif

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
                    <tr><th colspan="3">{{ $payment === null ? 'Total to pay on delivery' : 'Total' }}</th><th>BDT {{ $order['total'] }}</th></tr>
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
