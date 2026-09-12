{{--
    The basket. Website Phase 2, slice 2.1.

    ⚠ Every figure on this page was resolved from the price list a moment ago
    by CartPresenter. The template shows what it was handed; it never multiplies.

    ⛔ THERE IS NO CHECKOUT BUTTON YET, and the absence is deliberate rather than
    a button that goes nowhere. Slice 2.4 adds it, as a real ERP sales order.

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
                    </tfoot>
                </table>

                @if ($cart['problems'] > 0)
                    <p class="muted">
                        {{ $cart['problems'] === 1 ? 'One item' : $cart['problems'].' items' }} in your basket cannot be sold right now and {{ $cart['problems'] === 1 ? 'is' : 'are' }} not counted in the subtotal.
                    </p>
                @endif

                {{-- ⚠ Delivery is priced in slice 2.3 and stated at checkout in 2.4.
                     Nothing here guesses at it. --}}
                <p class="muted">Delivery is worked out at checkout.</p>

                <p><a href="/">Keep shopping</a></p>
            @endif
        </div>
    </section>
@endsection
