<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Testing
    |--------------------------------------------------------------------------
    |
    | ⚠⚠ THIS FILE EXISTS FOR ONE REASON: A CASE-SENSITIVITY BUG THAT ONLY
    | APPEARS ON LINUX.
    |
    | Inertia's default testing page path is `resource_path('js/Pages')`, with a
    | capital P. This project's directory is `resources/js/pages`, lowercase.
    |
    | On Windows, where this application is developed, the filesystem is
    | case-insensitive and `js/Pages` resolves to `js/pages` perfectly happily —
    | so all twenty-seven `assertInertia` tests passed. On Linux, where it is
    | deployed, they do not resolve, and every one of those tests fails with
    | "Inertia page component file [...] does not exist."
    |
    | Found on 2026-07-29 by running the suite inside the production container
    | for the first time. It is worth naming the shape: the suite was green
    | partly because of the developer's filesystem, and no amount of running it
    | locally would ever have revealed that.
    |
    */

    'testing' => [
        'ensure_pages_exist' => true,

        // Lowercase, matching the actual directory. Do not "correct" this to
        // `js/Pages` — that is the default this file exists to override.
        'page_paths' => [
            resource_path('js/pages'),
        ],

        'page_extensions' => [
            'js', 'jsx', 'svelte', 'ts', 'tsx', 'vue',
        ],
    ],

];
