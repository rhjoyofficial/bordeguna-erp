{{--
    One product, as this shop presents it.

    Phase 2, slice 2.1: the page now carries an add-to-basket form. ⚠ Whether
    the form is shown at all is decided by `availability`, which StorefrontPage
    resolved through the SAME class the cart's own refusal reads, so the page
    cannot offer what the button would then refuse.

    ⛔ Still no order form. There is no checkout until slice 2.4.
--}}
@extends('themes.'.$theme->key().'.layout')

@section('content')
    <section class="block">
        <div class="wrap split">
            <div>
                @if ($product['image'])
                    <img src="{{ $product['image']['url'] }}" alt="{{ $product['image']['alt'] ?? '' }}">
                @endif
            </div>
            <div>
                <h1>{{ $product['title'] }}</h1>
                @if ($product['summary'])
                    <p>{{ $product['summary'] }}</p>
                @endif
                @if ($product['price'])
                    <p class="price">BDT {{ $product['price'] }}</p>
                @else
                    {{-- ⚠ A price that is not in force is absent, never zero. --}}
                    <p class="muted">Price on request.</p>
                @endif

                @if ($product['availability']['sellable'])
                    @if ($product['availability']['message'])
                        {{-- ⚠ "Made to order" is a fact about stock, said plainly. --}}
                        <p class="muted">{{ $product['availability']['message'] }}</p>
                    @endif
                    {{-- ⭐ D24: the shop's ONE delivery promise, counted from confirmation. --}}
                    <p class="muted">{{ $store->deliveryPromise() }}</p>
                    <form method="post" action="/cart/lines" class="add-to-basket">
                        @csrf
                        <input type="hidden" name="product" value="{{ $product['id'] }}">
                        <label>Quantity
                            <input type="number" name="quantity" value="1" min="1" max="99">
                        </label>
                        <button type="submit">Add to basket</button>
                    </form>
                @elseif ($product['availability']['message'])
                    {{-- Out of season or out of stock: the URL stays, the button does not. --}}
                    <p class="muted">{{ $product['availability']['message'] }}</p>
                @endif

                @if ($errors->any())
                    <p class="error">{{ $errors->first() }}</p>
                @endif
            </div>
        </div>
    </section>
@endsection
