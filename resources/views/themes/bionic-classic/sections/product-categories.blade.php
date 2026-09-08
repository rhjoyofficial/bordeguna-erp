{{-- ⚠ Categories are drawn as labels only. A category LISTING page is a page in its own right (PageType::Listing), not something a hero block invents a URL for. --}}
<section class="block">
    <div class="wrap">
        @if (!empty($payload['heading']))<h2>{{ $payload['heading'] }}</h2>@endif
        <div class="grid">
            @foreach ($payload['categories'] ?? [] as $category)
                <div class="card"><div class="body">{{ is_array($category) ? ($category['label'] ?? '') : $category }}</div></div>
            @endforeach
        </div>
    </div>
</section>