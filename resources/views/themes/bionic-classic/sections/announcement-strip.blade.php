{{-- ⚠ A coupon code is SHOWN, never applied here: the basket has its own code box.
     ⛔ `$coupon` is the code only when the ERP says checkout would take it right now
     (slice 2.5b). An expired, switched-off or used-up code is not advertised. --}}
<div style="background:var(--gold);color:var(--ink);padding:10px 0">
    <div class="wrap">
        <span>{{ $payload['message'] ?? '' }}</span>
        @if (!empty($coupon))<strong> {{ $coupon }}</strong>@endif
        @if (!empty($payload['link']))<a href="{{ $payload['link'] }}"> More</a>@endif
    </div>
</div>