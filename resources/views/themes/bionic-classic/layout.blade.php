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
    <title>{{ $title ? $title.' | '.$store->name : $store->name }}</title>

    {{-- ⛔ A preview hostname must never be indexed. Slice 1.9 makes this a real
         setting; until then the safe answer is the one that cannot embarrass
         anybody, because a preview shop indexed under the company's name is very
         hard to undo. --}}
    <meta name="robots" content="noindex, nofollow">

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
    </style>
</head>
<body>
<header class="shop">
    <div class="wrap"><a href="/">{{ $store->name }}</a></div>
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
