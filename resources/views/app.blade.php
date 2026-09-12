<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- ⚠⚠ A THIRD PARTY CAN MANGLE OUR OWN SCRIPT BEFORE IT RUNS (Joy,
             12 Sep 2026: an ad blocker / antivirus web-scanner on his and a
             colleague's synced Chrome profile was rewriting one of the page's
             own JS chunks in flight, chosen at random each load - always a
             clean fetch() of the exact same URL seconds later, never the
             server). A single corrupted chunk then leaves the whole React
             root unmounted: a blank white page with no explanation.

             This has to sit BEFORE @vite as a plain classic script, not
             inside app.tsx - if the bundle carrying our own error handling
             is the one that gets mangled, app.tsx never runs at all. One
             automatic reload clears a one-off mangling; a SECOND failure in
             the same tab means something is doing this on every load, so it
             says so instead of reloading forever. --}}
        <script>
            (function () {
                var RELOAD_KEY = 'erp:asset-reload-attempted';

                function isOurAsset(url) {
                    return typeof url === 'string' && url.indexOf('/build/assets/') !== -1;
                }

                function handleAssetFailure() {
                    if (!sessionStorage.getItem(RELOAD_KEY)) {
                        sessionStorage.setItem(RELOAD_KEY, String(Date.now()));
                        window.location.reload();
                        return;
                    }

                    if (document.getElementById('asset-error-banner')) return;

                    var banner = document.createElement('div');
                    banner.id = 'asset-error-banner';
                    banner.style.cssText = 'position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center;background:#f7f8f7;font-family:system-ui,sans-serif;padding:24px;text-align:center;color:#1a1a1a;';
                    banner.innerHTML =
                        '<div style="max-width:480px;">' +
                        '<h1 style="font-size:18px;margin:0 0 12px;">This page could not load correctly</h1>' +
                        '<p style="margin:0 0 8px;color:#555;line-height:1.5;">Something on this computer or network (often an ad blocker or antivirus web-scanning) is altering this page\'s files as they load. Reloading did not fix it on its own.</p>' +
                        '<p style="margin:0 0 16px;color:#555;line-height:1.5;">Try Incognito mode, or temporarily turn off ad-block / HTTPS scanning for this site, then press Reload.</p>' +
                        '<button id="asset-error-retry" style="padding:8px 16px;border-radius:6px;border:1px solid #ccc;background:#fff;cursor:pointer;font-size:14px;">Reload</button>' +
                        '</div>';
                    document.body.appendChild(banner);
                    document.getElementById('asset-error-retry').addEventListener('click', function () {
                        sessionStorage.removeItem(RELOAD_KEY);
                        window.location.reload();
                    });
                }

                window.addEventListener('error', function (event) {
                    var url = event && event.filename;
                    if (!url && event && event.target && event.target !== window) {
                        url = event.target.src || event.target.href;
                    }
                    if (isOurAsset(url)) {
                        handleAssetFailure();
                    }
                }, true);

                window.addEventListener('unhandledrejection', function (event) {
                    var reason = event && event.reason;
                    var message = String((reason && reason.message) || reason || '');
                    if (message.indexOf('dynamically imported module') !== -1
                        || message.indexOf('Importing a module script failed') !== -1) {
                        handleAssetFailure();
                    }
                });
            })();
        </script>

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png">
        <link rel="icon" type="image/png" sizes="192x192" href="/favicon-192.png">
        <link rel="apple-touch-icon" href="/favicon-192.png">

        <link rel="preconnect" href="https://fonts.bunny.net">
        {{-- Berkshire Swash carries the brand name only (Joy, 27 Aug 2026). It is a
             display face: legible as a wordmark, poor as body text, so it is bound
             to one utility class rather than to any element. --}}
        <link href="https://fonts.bunny.net/css?family=geist:400,500,600,700|berkshire-swash:400" rel="stylesheet" />

        {{-- The face official documents are set in, chosen per business in
             Settings. Emitted server-side rather than applied from React so it
             is present in the very first paint: a print dialog opened before
             hydration must already be on the right font, and a document that
             prints in one face and re-renders in another is the failure this
             whole exercise is about.

             ⚠ ONLY the document variable. The interface keeps its own font -
             Joy was explicit that the sidebar and headings stay as they are. --}}
        <style>:root { --font-document: {!! \App\Modules\Foundation\Support\FontCatalogue::stack(\App\Modules\Foundation\Support\DocumentFont::current()) !!}; }</style>

        @routes
        @viteReactRefresh
        @vite(['resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
