<section class="block">
    <div class="wrap">
        @if (!empty($payload['heading']))<h2>{{ $payload['heading'] }}</h2>@endif
        <div class="grid">
        @foreach ($products as $item)
            <article class="card">
                @if ($item['image'])
                    <a href="{{ $item['url'] }}"><img src="{{ $item['image']['url'] }}" alt="{{ $item['image']['alt'] ?? '' }}"></a>
                @endif
                <div class="body">
                    <h3><a href="{{ $item['url'] }}">{{ $item['title'] }}</a></h3>
                    @if ($item['price'])<p class="price">BDT {{ $item['price'] }}</p>@endif
                </div>
            </article>
        @endforeach
    </div>
        @if (count($products) === 0)
            {{-- ⚠ Silent on the page. A section pointing only at products the
                 shop has taken off sale draws nothing rather than an apology a
                 visitor cannot act on. --}}
        @endif
    </div>
</section>