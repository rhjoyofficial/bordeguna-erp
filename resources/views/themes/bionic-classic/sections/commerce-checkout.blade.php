{{-- Phase 2, slice 2.1: the order block adds to the BASKET. ⛔ It still takes no order: there is no checkout until slice 2.4, and a form that looked like one would take an order into nothing. ⚠ Whether the button is shown is decided by `availability`, resolved by the same class the cart's refusal reads. --}}
<section class="block">
    <div class="wrap">
        @if (!empty($payload['heading']))<h2>{{ $payload['heading'] }}</h2>@endif
        @php($item = $products[$payload['product'] ?? null] ?? null)
        @if ($item)
            <p><a href="{{ $item['url'] }}">{{ $item['title'] }}</a></p>
            @if ($item['price'])<p class="price">BDT {{ $item['price'] }}</p>@endif
            @if ($item['availability']['sellable'])
                @if ($item['availability']['message'])<p class="muted">{{ $item['availability']['message'] }}</p>@endif
                <form method="post" action="/cart/lines" class="add-to-basket">
                    @csrf
                    <input type="hidden" name="product" value="{{ $item['id'] }}">
                    <label>Quantity <input type="number" name="quantity" value="1" min="1" max="99"></label>
                    <button type="submit">Add to basket</button>
                </form>
            @elseif ($item['availability']['message'])
                <p class="muted">{{ $item['availability']['message'] }}</p>
            @endif
        @endif
    </div>
</section>
