{{-- ⚠ A coupon code is SHOWN, never applied. Applying one needs a basket, and there is no basket. --}}
<div style="background:var(--gold);color:var(--ink);padding:10px 0">
    <div class="wrap">
        <span>{{ $payload['message'] ?? '' }}</span>
        @if (!empty($payload['coupon_code']))<strong> {{ $payload['coupon_code'] }}</strong>@endif
        @if (!empty($payload['link']))<a href="{{ $payload['link'] }}"> More</a>@endif
    </div>
</div>