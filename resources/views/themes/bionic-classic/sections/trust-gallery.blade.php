<section class="block">
    <div class="wrap">
        @if (!empty($payload['heading']))<h2>{{ $payload['heading'] }}</h2>@endif
        {{-- ⚠ Gallery images are admin-managed records, not theme assets. Until
             this shop has its own, the section draws its heading and stops. --}}
    </div>
</section>