<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Bug hunt 16 Oct 2026: My Work linked to /crm/follow-ups, a page that never
 * existed. Every fixed address a page links to (`href="/..."`, no template)
 * must be a GET route.
 */
it('links only to addresses that exist, on every page', function () {
    $broken = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('js'))) as $file) {
        if (! $file->isFile() || ! in_array($file->getExtension(), ['tsx', 'ts'], true)) {
            continue;
        }

        preg_match_all('/href="(\/[^"{}$`]*)"/', file_get_contents($file->getPathname()), $matches);

        foreach ($matches[1] as $href) {
            $path = strtok($href, '?#');

            try {
                // The fallback route answers every address with the 404 page.
                $found = ! Route::getRoutes()->match(Request::create($path, 'GET'))->isFallback;
            } catch (NotFoundHttpException|MethodNotAllowedHttpException) {
                $found = false;
            }

            if (! $found) {
                $broken[] = str_replace(resource_path('js'), '', $file->getPathname()).': '.$href;
            }
        }
    }

    expect($broken)->toBe([]);
});
