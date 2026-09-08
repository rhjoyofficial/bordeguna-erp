<section class="block">
    <div class="wrap">
        @if (!empty($payload['heading']))<h2>{{ $payload['heading'] }}</h2>@endif
        <ul>
            @foreach ($payload['notes'] ?? [] as $note)
                <li>{{ is_array($note) ? ($note['body'] ?? '') : $note }}</li>
            @endforeach
        </ul>
    </div>
</section>