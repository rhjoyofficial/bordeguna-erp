<?php

use App\Support\PerPage;
use Illuminate\Http\Request;

/**
 * 31 Oct 2026 (Joy): every list's footer offers 20, 50, 200, 500 or All rows,
 * 20 by default. Anything else in the address falls back to 20.
 */
it('reads the rows per page from the address', function (?string $asked, int $rows) {
    $request = Request::create('/x', 'GET', $asked === null ? [] : ['per_page' => $asked]);

    expect(PerPage::from($request))->toBe($rows);
})->with([
    'nothing asked' => [null, 20],
    '50' => ['50', 50],
    '200' => ['200', 200],
    '500' => ['500', 500],
    'all' => ['all', PerPage::ALL],
    'not offered' => ['37', 20],
    'nonsense' => ['lots', 20],
]);
