<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

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
