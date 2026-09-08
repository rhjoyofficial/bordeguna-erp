{{--
    One product, as this shop presents it.

    ⛔ READ ONLY. There is no add-to-cart and no order form, because Phase 1 has
    no cart and no checkout. A button that looked like it worked would take an
    order this system cannot yet fulfil, which is worse than no button.
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
            </div>
        </div>
    </section>
@endsection
