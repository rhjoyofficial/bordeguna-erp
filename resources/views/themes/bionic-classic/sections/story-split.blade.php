{{-- ⚠ A video id is NAMED, not embedded. Embedding loads a third party on a page a stranger opens; the real player arrives with a consent decision, not by accident. --}}
<section class="block">
    <div class="wrap split">
        @php($image = $media[$payload['image'] ?? null] ?? null)
        <div>
            @if (!empty($payload['video_id']))
                <p class="muted">Film: {{ $payload['video_id'] }}</p>
            @elseif ($image)
                <img src="{{ $image['url'] }}" alt="{{ $image['alt'] ?? '' }}">
            @endif
        </div>
        <div>
            @if (!empty($payload['heading']))<h2>{{ $payload['heading'] }}</h2>@endif
            <div>{!! nl2br(e($payload['body'] ?? '')) !!}</div>
        </div>
    </div>
</section>