{{-- ⛔⛔ NO ORDER FORM, AND THAT IS THE PHASE. Phase 1 is read only: there is no cart, no checkout and no route that takes a submission. A form here would look exactly like the live shop's and take an order into nothing. --}}
<section class="block">
    <div class="wrap">
        @if (!empty($payload['heading']))<h2>{{ $payload['heading'] }}</h2>@endif
        @php($item = $products[$payload['product'] ?? null] ?? null)
        @if ($item)
            <p><a href="{{ $item['url'] }}">{{ $item['title'] }}</a></p>
            @if ($item['price'])<p class="price">BDT {{ $item['price'] }}</p>@endif
        @endif
    </div>
</section>