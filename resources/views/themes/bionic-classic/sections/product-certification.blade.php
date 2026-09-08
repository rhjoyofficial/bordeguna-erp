{{-- ⛔ NO CERTIFICATION IS NAMED HERE. A badge with no certificate behind it is exactly the defect found on the live Bionic shop; certificates arrive as real records or not at all. --}}
<section class="block">
    <div class="wrap">
        @if (!empty($payload['heading']))<h2>{{ $payload['heading'] }}</h2>@endif
        @php($item = $products[$payload['product'] ?? null] ?? null)
        @if ($item)<p>{{ $item['title'] }}</p>@endif
    </div>
</section>