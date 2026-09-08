{{-- ⚠ The CTA is a LINK to the product page, never a buy button: there is no cart in Phase 1 and a control that pretends otherwise takes an order nobody can fulfil. --}}
<section class="block">
    <div class="wrap split">
        <div>
            @php($image = $media[$payload['image'] ?? null] ?? null)
            @if ($image)<img src="{{ $image['url'] }}" alt="{{ $image['alt'] ?? '' }}">@endif
        </div>
        <div>
            @if (!empty($payload['heading']))<h1>{{ $payload['heading'] }}</h1>@endif
            @if (!empty($payload['subheading']))<p>{{ $payload['subheading'] }}</p>@endif
            @php($item = $products[$payload['product'] ?? null] ?? null)
            @if ($item)
                @if ($item['price'])<p class="price">BDT {{ $item['price'] }}</p>@endif
                <p><a href="{{ $item['url'] }}">{{ $payload['cta_label'] ?? 'See this product' }}</a></p>
            @endif
        </div>
    </div>
</section>