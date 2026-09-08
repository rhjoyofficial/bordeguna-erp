{{-- ⚠ Every slide is drawn. A carousel needs script; a stack needs none and shows a visitor everything, which is the read-only phase's whole shape. --}}
<section class="block" style="padding:0">
    @foreach ($payload['slides'] ?? [] as $slide)
        @php($image = $media[$slide['image'] ?? null] ?? null)
        <div style="position:relative;background:var(--green);color:var(--ivory)">
            @if ($image)<img src="{{ $image['url'] }}" alt="{{ $image['alt'] ?? '' }}" style="opacity:.55">@endif
            <div class="wrap" style="padding:36px 20px">
                @if (!empty($slide['heading']))<h1 style="color:var(--ivory)">{{ $slide['heading'] }}</h1>@endif
                @if (!empty($slide['subheading']))<p>{{ $slide['subheading'] }}</p>@endif
                @if (!empty($slide['link']) && !empty($slide['cta_label']))
                    <p><a href="{{ $slide['link'] }}" style="color:var(--gold)">{{ $slide['cta_label'] }}</a></p>
                @endif
            </div>
        </div>
    @endforeach
</section>