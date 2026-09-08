{{-- ⚠ `e()` then `nl2br` - escaped FIRST, so stored copy can never inject markup into a public page, while its paragraphs still survive. --}}
<section class="block">
    <div class="wrap {{ empty($payload['image']) ? '' : 'split' }}">
        @php($image = $media[$payload['image'] ?? null] ?? null)
        @if ($image && ($payload['image_position'] ?? 'left') === 'left')
            <div><img src="{{ $image['url'] }}" alt="{{ $image['alt'] ?? '' }}"></div>
        @endif
        <div>
            @if (!empty($payload['heading']))<h2>{{ $payload['heading'] }}</h2>@endif
            <div>{!! nl2br(e($payload['body'] ?? '')) !!}</div>
        </div>
        @if ($image && ($payload['image_position'] ?? 'left') !== 'left')
            <div><img src="{{ $image['url'] }}" alt="{{ $image['alt'] ?? '' }}"></div>
        @endif
    </div>
</section>