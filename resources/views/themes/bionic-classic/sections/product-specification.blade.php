{{-- ⚠ Specifications come from the product master in a later slice. Until then this draws what the shop actually holds rather than inventing rows. --}}
<section class="block">
    <div class="wrap">
        @if (!empty($payload['heading']))<h2>{{ $payload['heading'] }}</h2>@endif
        @php($item = $products[$payload['product'] ?? null] ?? null)
        @if ($item)
            <p><a href="{{ $item['url'] }}">{{ $item['title'] }}</a></p>
            @if ($item['summary'])<p>{{ $item['summary'] }}</p>@endif
        @endif
    </div>
</section>