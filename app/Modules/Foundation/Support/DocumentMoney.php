<?php

namespace App\Modules\Foundation\Support;

/**
 * How a figure is written on a document, in one place.
 *
 * ⚠⚠ WHY THIS MOVED TO THE SERVER. Every print page in the ERP carried its own
 * copy of `Number(v).toLocaleString('en-BD', …)`. That was harmless while the
 * only renderer was a browser - and stops being harmless the moment the same
 * invoice is also drawn on an 80mm slip and exported as a PDF by PHP, because
 * then the customer can hold two papers describing one sale in two number
 * formats. One formatter, on the side every renderer can reach.
 *
 * ⚠ MEASURED, NOT ASSUMED. `(1234567.5).toLocaleString('en-BD', {min:2,max:2})`
 * returns "1,234,567.50" on this project's runtime, which is exactly what
 * `number_format($v, 2)` returns - western grouping, not lakh. Both were run
 * side by side before this class replaced anything, so no document's printed
 * figures change shape.
 */
final class DocumentMoney
{
    /** Two decimals, always: money on a document does not hide its paisa. */
    public static function amount(string|float|int|null $value): string
    {
        return number_format((float) ($value ?? 0), 2);
    }

    /**
     * Up to four decimals, with trailing zeros dropped.
     *
     * ⚠ Mirrors `toLocaleString('en-BD', {maximumFractionDigits: 4})`, which
     * prints "1" rather than "1.0000". A receipt reading "1.0000 x 250.00" is a
     * receipt somebody has to read twice.
     */
    public static function quantity(string|float|int|null $value): string
    {
        $formatted = number_format((float) ($value ?? 0), 4);

        if (! str_contains($formatted, '.')) {
            return $formatted;
        }

        return rtrim(rtrim($formatted, '0'), '.');
    }
}
