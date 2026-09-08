{{-- ⛔ A badge is drawn only if somebody put it in the payload. This theme adds none of its own, because an unearned mark on a food page is a claim. --}}
<section class="block">
    <div class="wrap">
        <div class="grid">
            @foreach ($payload['badges'] ?? [] as $badge)
                @php($image = $media[$badge['image'] ?? null] ?? null)
                <div class="card"><div class="body">
                    @if ($image)<img src="{{ $image['url'] }}" alt="{{ $image['alt'] ?? '' }}" style="max-height:70px;width:auto">@endif
                    <p>{{ $badge['label'] ?? '' }}</p>
                </div></div>
            @endforeach
        </div>
    </div>
</section>