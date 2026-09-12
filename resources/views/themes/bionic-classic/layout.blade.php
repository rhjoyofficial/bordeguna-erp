{{--
    Bionic Classic: a faithful rebuild, not a redesign.

    ⭐ Joy's decision, recorded in the theme class: changing the design at cutover
    would confound any conversion drop, because two things would have changed at
    once and neither could be blamed. A refresh becomes a second theme, later.

    ⚠ The palette is the approved Alignment Pack's, not invented here: deep green,
    warm ivory, gold.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $seo['title'] }}</title>

    @if ($seo['description'])
        <meta name="description" content="{{ $seo['description'] }}">
    @endif

    {{-- ⚠⚠ THE CANONICAL CARRIES THE QUERY STRING AND THE PRIMARY HOSTNAME. Built
         from the path alone it told search engines that `?page=4` was page 1;
         built from the REQUESTED host it let two hostnames each claim to be
         canonical, which is how one shop became two complete copies of itself in
         an index. Both were measured on a live shop on 5 September. --}}
    <link rel="canonical" href="{{ $seo['canonical'] }}">

    {{-- ⛔ A shop is `noindex` until somebody deliberately makes it indexable. A
         preview shop in a search index is very hard to undo: removing it is a
         request to a third party, not a change anybody here can make. --}}
    <meta name="robots" content="{{ $seo['robots'] }}">

    @foreach ($seo['schemas'] as $schema)
        {{-- ⛔ Nothing here is claimed that the page does not have: no rating on
             an unreviewed product, no availability this system does not hold for
             the web, no address the business never entered. --}}
        <script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
    @endforeach

    {{-- ⚠ Self-hosted, and the same face the ERP's own documents use. A shop and
         its paperwork disagreeing about typography reads as two companies. --}}
    <style>
        :root {
            --green: #18693b;
            --ivory: #fbf8f1;
            --gold: #c5a34e;
            --ink: #1b1b18;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: var(--ivory);
            color: var(--ink);
            font-family: 'Tiro Bangla', Georgia, serif;
            line-height: 1.6;
        }
        img { max-width: 100%; height: auto; display: block; }
        a { color: var(--green); }
        .wrap { max-width: 1140px; margin: 0 auto; padding: 0 20px; }
        header.shop { background: var(--green); color: var(--ivory); padding: 18px 0; }
        header.shop a { color: var(--ivory); text-decoration: none; font-size: 22px; font-weight: 700; }
        section.block { padding: 44px 0; }
        section.block + section.block { border-top: 1px solid rgba(24,105,59,.12); }
        h1, h2 { color: var(--green); line-height: 1.25; margin: 0 0 14px; }
        .grid { display: grid; gap: 22px; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); }
        .card { background: #fff; border: 1px solid rgba(24,105,59,.14); border-radius: 10px; overflow: hidden; }
        .card .body { padding: 14px; }
        .price { color: var(--gold); font-weight: 700; }
        .muted { color: #6b6b63; }
        footer.shop { background: var(--green); color: var(--ivory); padding: 28px 0; margin-top: 40px; }
        .split { display: grid; gap: 28px; grid-template-columns: 1fr; }
        @media (min-width: 760px) { .split { grid-template-columns: 1fr 1fr; align-items: center; } }
        header.shop .wrap { display: flex; justify-content: space-between; align-items: center; }
        header.shop a.basket { font-size: 16px; font-weight: 400; }
        .notice { color: var(--green); }
        .error { color: #a33; }
        form.add-to-basket { margin-top: 14px; display: flex; gap: 12px; align-items: end; flex-wrap: wrap; }
        form.inline { display: inline-flex; gap: 6px; align-items: center; }
        input[type=number] { width: 64px; padding: 6px; }
        button { background: var(--green); color: var(--ivory); border: 0; padding: 8px 16px; border-radius: 6px; cursor: pointer; }
        table.basket { width: 100%; border-collapse: collapse; margin: 14px 0; }
        table.basket th, table.basket td { text-align: left; padding: 10px 8px; border-bottom: 1px solid rgba(24,105,59,.14); vertical-align: top; }
        table.basket tr.problem td { background: rgba(197,163,78,.10); }
    </style>
</head>
<body>
<header class="shop">
    <div class="wrap">
        <a href="/">{{ $store->name }}</a>
        {{-- Slice 2.1. ⚠ `basket` arrives from the view composer on every
             theme layout, so no controller has to remember to pass it. --}}
        @isset ($basket)
            <a class="basket" href="{{ $basket['url'] }}">Basket ({{ $basket['count'] }})</a>
        @endisset
    </div>
</header>

<main>
    @yield('content')
</main>

<footer class="shop">
    <div class="wrap">
        <p>&copy; {{ date('Y') }} {{ $store->name }}</p>
        {{-- ⛔ Nothing here claims an address, a phone number or a licence. The
             ERP holds those and this theme does not invent them; an unset detail
             is printed nowhere rather than guessed at. --}}
    </div>
</footer>
</body>
</html>
