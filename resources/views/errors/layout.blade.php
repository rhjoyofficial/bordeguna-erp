{{--
    The shell every error page uses.

    ⚠⚠ DELIBERATELY BLADE, NOT INERTIA, AND DELIBERATELY SELF-CONTAINED. An
    error page has to render when the application is broken - that is its entire
    job. An Inertia page needs the JavaScript bundle to load and the shared props
    to resolve, and those props query the database for the current business and
    the user's permissions. On the one page that exists to explain a database
    failure, that would throw while explaining the throw.

    ⚠ No external font, no CDN, no asset from the build. If the network is the
    problem, or the deploy that just went out is the problem, this page still
    works. That is worth more than matching the sidebar exactly.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') &middot; {{ config('app.name') }}</title>
    <style>
        :root {
            --ground: #f6f8f5;
            --surface: #ffffff;
            --ink: #16211b;
            --ink-2: #52625a;
            --rule: #d6e0d8;
            --accent: #1f6b41;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --ground: #0e1512;
                --surface: #161f1a;
                --ink: #e6ede8;
                --ink-2: #9fb0a7;
                --rule: #2a382f;
                --accent: #5cb684;
            }
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 2rem 1.25rem;
            background: var(--ground);
            color: var(--ink);
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
        }

        .card {
            width: 100%;
            max-width: 30rem;
            background: var(--surface);
            border: 1px solid var(--rule);
            border-radius: 10px;
            padding: 2rem;
            text-align: center;
        }

        .brand {
            font-family: Georgia, "Times New Roman", serif;
            font-size: 0.95rem;
            letter-spacing: 0.02em;
            color: var(--accent);
            margin: 0 0 1.75rem;
        }

        .code {
            font-family: ui-monospace, SFMono-Regular, Consolas, monospace;
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.18em;
            color: var(--ink-2);
            margin: 0 0 0.6rem;
        }

        h1 {
            font-size: 1.35rem;
            font-weight: 600;
            line-height: 1.25;
            margin: 0 0 0.75rem;
            text-wrap: balance;
        }

        p {
            margin: 0 0 1.5rem;
            color: var(--ink-2);
            font-size: 0.95rem;
        }

        a.back {
            display: inline-block;
            background: var(--accent);
            color: #fff;
            text-decoration: none;
            padding: 0.55rem 1.15rem;
            border-radius: 6px;
            font-size: 0.9rem;
            font-weight: 600;
        }

        a.back:focus-visible { outline: 2px solid var(--ink); outline-offset: 2px; }
    </style>
</head>
<body>
    <div class="card">
        {{-- ⛔ The brand is written `Bor de Guna` - lowercase "de", always. --}}
        <p class="brand">Bor de Guna ERP</p>
        <p class="code">@yield('code')</p>
        <h1>@yield('title')</h1>
        <p>@yield('message')</p>
        <a class="back" href="{{ url('/dashboard') }}">Back to the dashboard</a>
    </div>
</body>
</html>
