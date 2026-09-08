{{-- ⚠ The poster is shown and the player is not loaded. See story-split: a third party on a stranger's page is a consent decision. --}}
<section class="block">
    <div class="wrap">
        @if (!empty($payload['heading']))<h2>{{ $payload['heading'] }}</h2>@endif
        @php($poster = $media[$payload['poster'] ?? null] ?? null)
        @if ($poster)<img src="{{ $poster['url'] }}" alt="{{ $poster['alt'] ?? '' }}">@endif
        @if (!empty($payload['body']))<p>{{ $payload['body'] }}</p>@endif
        @if (!empty($payload['video_id']))<p class="muted">Film: {{ $payload['video_id'] }}</p>@endif
    </div>
</section>