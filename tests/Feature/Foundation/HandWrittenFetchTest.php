<?php

use Illuminate\Support\Facades\Route;

/**
 * ⚠⚠ WHY THIS FILE EXISTS.
 *
 * The counter sale screen looked up its price with a POST carrying
 * `X-CSRF-TOKEN`, read from `meta[name="csrf-token"]`. This application's layout
 * has never emitted that tag, so the header was always empty, every call was
 * rejected 419, and `if (!response.ok) return` swallowed it in silence. The
 * counter simply never filled in a price.
 *
 * Nothing caught it. Every test called the endpoint directly, so the route, the
 * validation and the resolver were all proved; no test can press a button. It
 * survived because the counter has never been used in production - the same
 * shape as the three unreachable screens.
 *
 * There is a second, quieter reason a hand-written fetch must not mutate.
 * EnsurePageBusinessMatches stamps every mutating request with the business the
 * page was built for, and it does that through Inertia's `router.on('before')`
 * hook. A raw fetch never passes through it, so a mutating fetch is the one
 * write-shaped request in the app that escapes the tenant assertion.
 *
 * So: every endpoint a hand-written fetch calls must be a SAFE method, and no
 * front-end source may reach for a CSRF token that does not exist.
 */
function frontEndSources(): array
{
    $files = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(resource_path('js'), FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (in_array($file->getExtension(), ['ts', 'tsx'], true)) {
            $files[$file->getPathname()] = file_get_contents($file->getPathname());
        }
    }

    return $files;
}

it('never reads a CSRF token the layout does not emit', function () {
    $layout = file_get_contents(resource_path('views/app.blade.php'));
    $emitsTag = str_contains($layout, 'name="csrf-token"');

    $offenders = [];

    foreach (frontEndSources() as $path => $source) {
        if (str_contains($source, 'csrf-token')) {
            $offenders[] = str_replace(resource_path('js').DIRECTORY_SEPARATOR, '', $path);
        }
    }

    // If the layout ever starts emitting the tag this guard relaxes on its own,
    // which is the honest behaviour: the trap is the MISMATCH, not the tag.
    expect($emitsTag || $offenders === [])->toBeTrue(
        'These files read meta[name="csrf-token"], which app.blade.php does not emit, so the header is always empty and every such request is rejected 419: '
        .implode(', ', $offenders)
    );
});

it('answers every hand-written fetch with a safe method', function () {
    // The endpoints the front end calls with `fetch` rather than through
    // Inertia. Each must be GET: no token to forget, and no mutating request
    // slipping past the business stamp.
    $handWritten = [
        'sales.counter.price',
        'inventory.products.sku-proposal',
        // ⭐ B6 (24 Oct 2026): the batch picker on the stock correction popup.
        'inventory.stock.batch-options',
    ];

    // ⚠ Built as a list and compared once. `toContain` takes further EXPECTED
    // VALUES as its extra arguments, not a failure message - passing an
    // explanation there quietly asserts that the explanation is in the array
    // too, and the test fails for the wrong reason. That trap has bitten this
    // project before.
    $unsafe = [];

    foreach ($handWritten as $name) {
        $route = Route::getRoutes()->getByName($name);

        if ($route === null) {
            $unsafe[] = "{$name} (route is gone; this guard needs updating)";

            continue;
        }

        $methods = array_diff($route->methods(), ['HEAD']);

        if ($methods !== ['GET']) {
            $unsafe[] = $name.' answers '.implode('/', $methods);
        }
    }

    expect($unsafe)->toBe([]);
});
