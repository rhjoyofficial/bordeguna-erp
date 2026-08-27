<?php

use App\Modules\Inventory\Support\Code128;

/**
 * The barcode encoder, checked against the symbology rather than against
 * itself.
 *
 * ⚠⚠ A WRONG BARCODE IS WORSE THAN NO BARCODE. It looks entirely correct on
 * screen and on paper, and the failure surfaces at a counter, with a customer
 * waiting, weeks later. So the checksum arithmetic is worked out by hand here
 * from the Code 128 rules rather than recorded from whatever this code happened
 * to produce - a test that captures its own output proves only that the code has
 * not changed, not that it was ever right.
 */

/** Every Code 128 symbol is 11 modules wide; the stop pattern carries 2 more. */
function moduleCount(int $symbols): int
{
    return $symbols * 11 + 2;
}

it('encodes an all-digit code in the compact set', function () {
    // "12345678" -> Start C, 12, 34, 56, 78, check, Stop = 7 symbols.
    // Checksum by hand: 105 + 12*1 + 34*2 + 56*3 + 78*4
    //                 = 105 + 12 + 68 + 168 + 312 = 665;  665 % 103 = 47.
    $widths = Code128::widths('12345678');

    expect(array_sum($widths))->toBe(moduleCount(7));

    // Start C is pattern 211232, so the symbol opens with those exact widths.
    expect(array_slice($widths, 0, 6))->toBe([2, 1, 1, 2, 3, 2]);
});

it('encodes text in set B', function () {
    // "HELLO" -> Start B, 40, 37, 44, 44, 47, check, Stop = 8 symbols.
    // Checksum: 104 + 40*1 + 37*2 + 44*3 + 44*4 + 47*5
    //         = 104 + 40 + 74 + 132 + 176 + 235 = 761;  761 % 103 = 40.
    $widths = Code128::widths('HELLO');

    expect(array_sum($widths))->toBe(moduleCount(8));

    // Start B is pattern 211214.
    expect(array_slice($widths, 0, 6))->toBe([2, 1, 1, 2, 1, 4]);
});

/**
 * ⚠ An ODD number of digits cannot be paired, so it must fall back to set B and
 * get wider - not silently drop a digit or pad one on.
 */
it('falls back to set B for an odd number of digits', function () {
    $widths = Code128::widths('1234567');

    // Start B, 7 digits, check, Stop = 10 symbols.
    expect(array_sum($widths))->toBe(moduleCount(10))
        ->and(array_slice($widths, 0, 6))->toBe([2, 1, 1, 2, 1, 4]);
});

/**
 * ⚠ The point of set C, shown rather than asserted in the abstract: EIGHT digits
 * paired into set C print NARROWER than SEVEN digits that could not be paired.
 */
it('prints more digits in less space when they can be paired', function () {
    $paired = array_sum(Code128::widths('12345678'));   // 8 digits, set C
    $unpaired = array_sum(Code128::widths('1234567'));  // 7 digits, set B

    expect($paired)->toBe(moduleCount(7))
        ->and($unpaired)->toBe(moduleCount(10))
        ->and($paired)->toBeLessThan($unpaired);
});

/** ⚠ Every symbol must be 11 modules. A wrong pattern is caught here. */
it('keeps every symbol eleven modules wide', function () {
    $widths = Code128::widths('BDG-500G-01');
    $symbols = array_chunk($widths, 6);

    // The trailing 2 is the stop bar, not a symbol.
    $last = array_pop($symbols);

    foreach ($symbols as $index => $symbol) {
        expect(array_sum($symbol))->toBe(11, "symbol {$index} is not 11 modules");
    }

    expect($last)->toBe([2]);
});

it('always ends with a terminating bar', function () {
    $widths = Code128::widths('12345678');

    expect(end($widths))->toBe(2);
});

it('refuses an empty value', function () {
    expect(fn () => Code128::widths(''))->toThrow(InvalidArgumentException::class, 'A barcode needs a value.');
});

/**
 * ⚠ Bangla, an accent or an emoji cannot be encoded by this symbology. Refusing
 * is the honest answer; encoding the bytes would print a symbol that scans as
 * something else entirely.
 */
it('refuses anything outside printable ascii', function () {
    expect(fn () => Code128::widths('বরদেগুনা'))
        ->toThrow(InvalidArgumentException::class, 'printable ASCII');
});

it('draws an svg sized in millimetres', function () {
    $svg = Code128::svg('12345678', widthMm: 32, heightMm: 12);

    expect($svg)->toContain('width="32mm"')
        ->and($svg)->toContain('height="12mm"')
        ->and($svg)->toContain('shape-rendering="crispEdges"')
        ->and($svg)->toContain('viewBox="0 0 79 100"')
        // 7 symbols x 3 bars, plus the terminating bar.
        ->and(substr_count($svg, '<rect'))->toBe(22);
});

it('escapes the value in the accessible label', function () {
    expect(Code128::svg('A&B"C'))->toContain('aria-label="Barcode A&amp;B&quot;C"');
});
