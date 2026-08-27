<?php

namespace App\Modules\Inventory\Support;

use InvalidArgumentException;

/**
 * Turns a barcode value into the bars a scanner reads.
 *
 * ⚠⚠ HAND-ROLLED ON PURPOSE, AND ONLY BECAUSE CODE 128 IS SMALL ENOUGH TO BE.
 * It is a fixed pattern table plus a modulo-103 checksum: deterministic, fully
 * testable, no dependency. QR is NOT in the same class - it needs Reed-Solomon
 * error correction and mask selection, and a hand-written one produces codes
 * that scan on a phone and fail on the scanner at the counter. If QR is ever
 * wanted it comes from a real library, as a decision somebody makes on purpose.
 *
 * ⚠ Code 128 encodes ANY of ASCII 32-126, so it takes whatever is in the
 * `barcode` column without caring whether it looks like an EAN. That matters
 * here: the column is free text and a product can carry a supplier's printed
 * code. ⛔ It is NOT EAN-13 - if Bor de Guna ever registers real GS1 numbers,
 * that is a different symbology with a check digit of its own, and pretending
 * Code 128 is the same thing would print labels no retailer accepts.
 */
class Code128
{
    /**
     * The 107 symbol patterns, as alternating bar/space widths starting with a
     * bar. Index is the Code 128 value; 103-105 are Start A/B/C and 106 is Stop.
     *
     * @var list<string>
     */
    private const PATTERNS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312',
        '132212', '221213', '221312', '231212', '112232', '122132', '122231', '113222',
        '123122', '123221', '223211', '221132', '221231', '213212', '223112', '312131',
        '311222', '321122', '321221', '312212', '322112', '322211', '212123', '212321',
        '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121',
        '313121', '211331', '231131', '213113', '213311', '213131', '311123', '311321',
        '331121', '312113', '312311', '332111', '314111', '221411', '431111', '111224',
        '111422', '121124', '121421', '141122', '141221', '112214', '112412', '122114',
        '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112',
        '421211', '212141', '214121', '412121', '111143', '111341', '131141', '114113',
        '114311', '411113', '411311', '113141', '114131', '311141', '411131', '211412',
        '211214', '211232', '233111',
    ];

    private const START_B = 104;

    private const START_C = 105;

    private const STOP = 106;

    /**
     * The widths of every bar and space, in order, starting with a bar.
     *
     * @return list<int>
     */
    public static function widths(string $value): array
    {
        if ($value === '') {
            throw new InvalidArgumentException('A barcode needs a value.');
        }

        if (preg_match('/[^\x20-\x7E]/', $value) === 1) {
            throw new InvalidArgumentException('A barcode may only contain printable ASCII.');
        }

        // ⚠ Set C packs TWO digits into one symbol, so an all-digit code of even
        // length prints at half the width. On a 25mm jar label that is the
        // difference between a code a scanner reads and one it does not.
        $useSetC = ctype_digit($value) && strlen($value) % 2 === 0;

        $start = $useSetC ? self::START_C : self::START_B;
        $values = [$start];

        if ($useSetC) {
            foreach (str_split($value, 2) as $pair) {
                $values[] = (int) $pair;
            }
        } else {
            foreach (str_split($value) as $char) {
                $values[] = ord($char) - 32;
            }
        }

        $values[] = self::checksum($values);
        $values[] = self::STOP;

        $widths = [];

        foreach ($values as $symbol) {
            foreach (str_split(self::PATTERNS[$symbol]) as $width) {
                $widths[] = (int) $width;
            }
        }

        // ⚠ The stop pattern carries two extra bar modules. Without them the
        // symbol has no terminating bar and scanners reject it.
        $widths[] = 2;

        return $widths;
    }

    /**
     * ⚠ Position-weighted, and the START symbol counts as position ZERO - it is
     * added once, not multiplied. Weighting it like the rest is the classic way
     * to produce a symbol that looks right and never scans.
     *
     * @param  list<int>  $values
     */
    private static function checksum(array $values): int
    {
        $sum = $values[0];

        for ($i = 1; $i < count($values); $i++) {
            $sum += $values[$i] * $i;
        }

        return $sum % 103;
    }

    /**
     * The symbol as an SVG, sized in millimetres so a printed label is the size
     * it claims to be.
     */
    public static function svg(string $value, float $widthMm = 32, float $heightMm = 12): string
    {
        $widths = self::widths($value);
        $modules = array_sum($widths);

        // One module wide in user units; the viewBox does the scaling, so the
        // bars stay crisp at any physical size.
        $x = 0;
        $bars = '';
        $isBar = true;

        foreach ($widths as $width) {
            if ($isBar) {
                $bars .= sprintf('<rect x="%d" y="0" width="%d" height="100" fill="#000"/>', $x, $width);
            }

            $x += $width;
            $isBar = ! $isBar;
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d 100" width="%smm" height="%smm" '
            .'preserveAspectRatio="none" shape-rendering="crispEdges" role="img" aria-label="Barcode %s">%s</svg>',
            $modules,
            rtrim(rtrim(number_format($widthMm, 2, '.', ''), '0'), '.'),
            rtrim(rtrim(number_format($heightMm, 2, '.', ''), '0'), '.'),
            htmlspecialchars($value, ENT_QUOTES),
            $bars,
        );
    }
}
