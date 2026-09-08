<section class="block">
    <div class="wrap">
        @if (!empty($payload['heading']))<h2>{{ $payload['heading'] }}</h2>@endif
        @foreach ($payload['questions'] ?? [] as $entry)
            <details>
                <summary>{{ is_array($entry) ? ($entry['question'] ?? '') : $entry }}</summary>
                @if (is_array($entry) && !empty($entry['answer']))<p>{{ $entry['answer'] }}</p>@endif
            </details>
        @endforeach
        {{-- ⚠ `include_derived` asks for answers computed from real shipping and
             payment settings so they cannot go stale. That generator is a slice
             of its own; until it exists the flag draws nothing rather than a
             plausible answer nobody checked. --}}
    </div>
</section>