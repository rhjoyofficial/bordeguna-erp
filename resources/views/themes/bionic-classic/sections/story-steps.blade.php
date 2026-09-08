<section class="block">
    <div class="wrap">
        @if (!empty($payload['heading']))<h2>{{ $payload['heading'] }}</h2>@endif
        <ol>
            @foreach ($payload['steps'] ?? [] as $step)
                <li>
                    <strong>{{ is_array($step) ? ($step['heading'] ?? '') : $step }}</strong>
                    @if (is_array($step) && !empty($step['body'])) <span>{{ $step['body'] }}</span>@endif
                </li>
            @endforeach
        </ol>
    </div>
</section>