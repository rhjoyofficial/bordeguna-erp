<?php

/**
 * INV-007 (audit of 14 Oct 2026): the Transfers and Stock requests tables had
 * no horizontal scroll wrapper, so at phone width the last column (Receive,
 * Approve, Reject) fell off the screen. Every other inventory table scrolls
 * inside itself; app-content.tsx relies on that.
 *
 * ⚠ File-based, like the sidebar checks: there is no browser in the suite, and
 * reading the page source is the honest check that the wrapper is there.
 */
dataset('action tables', [
    'transfers' => ['js/pages/inventory/stock/transfers.tsx'],
    'stock requests' => ['js/pages/inventory/stock-requests/index.tsx'],
]);

it('wraps every table whose last column holds the actions in a horizontal scroller', function (string $page) {
    $source = file_get_contents(resource_path($page));

    preg_match_all('/<table\b/', $source, $tables);
    preg_match_all('/<div className="overflow-x-auto">\s*<table className="[^"]*min-w-\[/', $source, $wrapped);

    expect(count($tables[0]))->toBeGreaterThan(0)
        ->and(count($wrapped[0]))->toBe(count($tables[0]));
})->with('action tables');
