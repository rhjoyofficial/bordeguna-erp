<section class="block">
    <div class="wrap">
        @php($image = $media[$payload['image'] ?? null] ?? null)
        @if ($image)
            <figure style="margin:0">
                <img src="{{ $image['url'] }}" alt="{{ $image['alt'] ?? '' }}">
                @if (!empty($payload['caption']))<figcaption class="muted">{{ $payload['caption'] }}</figcaption>@endif
            </figure>
        @endif
        @if (!empty($payload['notes']))
            <ul>@foreach ($payload['notes'] as $note)<li>{{ is_array($note) ? ($note['body'] ?? '') : $note }}</li>@endforeach</ul>
        @endif
    </div>
</section>