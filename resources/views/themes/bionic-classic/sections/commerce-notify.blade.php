<section class="block">
    <div class="wrap">
        @if (!empty($payload['heading']))<h2>{{ $payload['heading'] }}</h2>@endif
        @if (!empty($payload['body']))<p>{{ $payload['body'] }}</p>@endif
        {{-- ⛔ No email box. Collecting an address needs somewhere to put it and
             something to do with it, and Phase 1 has neither. --}}
    </div>
</section>