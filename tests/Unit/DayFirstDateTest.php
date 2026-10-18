<?php

use App\Support\DayFirstDate;

/*
 * Joy, 18 Oct 2026: "in our country we always write Day Month Year". A typed
 * or imported date is read DAY first, never in the American order.
 */

it('reads a typed date day first and keeps ISO as ISO', function (string $typed, ?string $iso) {
    expect(DayFirstDate::toIso($typed))->toBe($iso);
})->with([
    ['17/09/2026', '2026-09-17'],
    ['05/09/2026', '2026-09-05'],
    ['5/9/2026', '2026-09-05'],
    ['10/03/2025', '2025-03-10'],
    ['17-09-2026', '2026-09-17'],
    ['17.09.2026', '2026-09-17'],
    ['2026-09-17', '2026-09-17'],
    ['2026/09/17', '2026-09-17'],
    ['১৭/০৯/২০২৬', '2026-09-17'],
    ['09/17/2026', null],
    ['31/02/2025', null],
    ['17/09/26', null],
    ['tomorrow', null],
    ['', null],
]);

it('prints a stored date as DD/MM/YYYY', function () {
    expect(DayFirstDate::show('2026-09-17'))->toBe('17/09/2026')
        ->and(DayFirstDate::show('2026-09-17 14:05:00'))->toBe('17/09/2026')
        ->and(DayFirstDate::show(null))->toBe('-')
        ->and(DayFirstDate::show('17 Sep 2026'))->toBe('17 Sep 2026')
        ->and(DayFirstDate::show(new DateTimeImmutable('2026-01-02')))->toBe('02/01/2026');
});
