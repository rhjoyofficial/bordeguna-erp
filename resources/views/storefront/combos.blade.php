{{--
    Every bundle this shop can sell right now. Website Phase 2, slice 2.5a.

    ⚠ Only sellable bundles are listed. One that would refuse at the button does
    not belong where somebody chooses; its own page stays and explains itself.
--}}
@extends('themes.'.$theme->key().'.layout')

@section('content')
    <section class="block">
        <div class="wrap">
            <h1>Bundles</h1>

            @if ($combos === [])
                <p class="muted">There are no bundles on sale right now.</p>
            @else
                <div class="grid">
                    @foreach ($combos as $combo)
                        <article class="card">
                            @if ($combo['image'])
                                <a href="{{ $combo['url'] }}"><img src="{{ $combo['image']['url'] }}" alt="{{ $combo['image']['alt'] ?? '' }}"></a>
                            @endif
                            <div class="body">
                                <h3><a href="{{ $combo['url'] }}">{{ $combo['title'] }}</a></h3>
                                <p class="muted">
                                    @foreach ($combo['parts'] as $part){{ $part['quantity'] }} &times; {{ $part['title'] }}@if (! $loop->last), @endif @endforeach
                                </p>
                                <p class="price">BDT {{ $combo['price'] }}</p>
                                @if ($combo['saving'])
                                    <p class="muted">You save BDT {{ $combo['saving'] }}</p>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </div>
    </section>
@endsection
