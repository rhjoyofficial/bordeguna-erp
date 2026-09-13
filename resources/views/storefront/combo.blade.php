{{--
    One bundle, as this shop sells it. Website Phase 2, slice 2.5a.

    ⚠ Every figure came from ComboPricing through ComboPresenter - the same class
    the basket and the checkout ask - so this page cannot promise a price the
    order will not record. The template adds nothing up.

    ⚠ A saving is shown only when there is one. "Save BDT 0" is a claim the shop
    is not making, and a strike-through price with no real saving behind it is
    the oldest trick a shop can be caught in.
--}}
@extends('themes.'.$theme->key().'.layout')

@section('content')
    <section class="block">
        <div class="wrap split">
            <div>
                @if ($combo['image'])
                    <img src="{{ $combo['image']['url'] }}" alt="{{ $combo['image']['alt'] ?? '' }}">
                @endif
            </div>
            <div>
                <h1>{{ $combo['title'] }}</h1>
                @if ($combo['summary'])
                    <p>{{ $combo['summary'] }}</p>
                @endif

                <h2>In this bundle</h2>
                <ul class="bundle-parts">
                    @foreach ($combo['parts'] as $part)
                        <li>{{ $part['quantity'] }} &times; {{ $part['title'] }}</li>
                    @endforeach
                </ul>

                @if ($combo['price'])
                    <p class="price">BDT {{ $combo['price'] }}</p>
                    @if ($combo['saving'])
                        <p class="muted">Bought separately: BDT {{ $combo['parts_total'] }}. You save BDT {{ $combo['saving'] }}.</p>
                    @endif
                @endif

                @if ($combo['availability']['sellable'])
                    @if ($combo['availability']['message'])
                        <p class="muted">{{ $combo['availability']['message'] }}</p>
                    @endif
                    {{-- ⭐ D24: the shop's ONE delivery promise, counted from confirmation. --}}
                    <p class="muted">{{ $store->deliveryPromise() }}</p>
                    <form method="post" action="/cart/combos" class="add-to-basket">
                        @csrf
                        <input type="hidden" name="combo" value="{{ $combo['id'] }}">
                        <label>Quantity
                            <input type="number" name="quantity" value="1" min="1" max="99">
                        </label>
                        <button type="submit">Add to basket</button>
                    </form>
                @elseif ($combo['availability']['message'])
                    {{-- The address stays; the button does not, and the page says why. --}}
                    <p class="muted">{{ $combo['availability']['message'] }}</p>
                @endif

                @if ($errors->any())
                    <p class="error">{{ $errors->first() }}</p>
                @endif
            </div>
        </div>
    </section>
@endsection
