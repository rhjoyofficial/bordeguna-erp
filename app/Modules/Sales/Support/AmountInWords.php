<?php

namespace App\Modules\Sales\Support;

/**
 * The total, spelled out, the way a commercial invoice spells it.
 *
 * ⚠⚠ THIS IS NOT DECORATION. Writing the amount in words is the oldest
 * anti-tampering device on a commercial document: a figure can have a digit
 * added in front of it, a sentence cannot. Every invoice, cheque and delivery
 * note in this market carries it, and its absence is one of the things that
 * makes a document look unofficial.
 *
 * ⭐ USES THE SOUTH ASIAN NUMBERING SYSTEM - lakh and crore - deliberately, and
 * not the international million. The customer reading this is in Bangladesh,
 * and "Three lakh forty thousand" is the phrase they will check against the
 * figure. "Three hundred and forty thousand" would be correct arithmetic and
 * the wrong document.
 */
final class AmountInWords
{
    private const ONES = [
        '', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine',
        'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen',
        'Seventeen', 'Eighteen', 'Nineteen',
    ];

    private const TENS = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    /**
     * @param  string|float  $amount  a decimal amount, e.g. "39470.00"
     */
    public static function taka(string|float $amount): string
    {
        $value = round((float) $amount, 2);
        $negative = $value < 0;
        $value = abs($value);

        $whole = (int) floor($value);
        // ⚠ Rounded, not truncated. 0.985 is 99 poisha on the invoice total, so
        // it must be 99 in the words too or the two halves of the document
        // disagree by a poisha and somebody has to work out which is right.
        $poisha = (int) round(($value - $whole) * 100);

        if ($poisha === 100) {
            $whole++;
            $poisha = 0;
        }

        $words = $whole === 0 ? 'Zero' : self::convert($whole);

        if ($poisha > 0) {
            $words .= ' and '.self::convert($poisha).' Poisha';
        }

        return ($negative ? 'Minus ' : '').$words.' Taka Only';
    }

    private static function convert(int $number): string
    {
        if ($number < 0) {
            return '';
        }

        $parts = [];

        // Crore, then lakh, then the last five digits as thousands and hundreds.
        $crore = intdiv($number, 10000000);
        $number %= 10000000;

        $lakh = intdiv($number, 100000);
        $number %= 100000;

        $thousand = intdiv($number, 1000);
        $number %= 1000;

        $hundred = intdiv($number, 100);
        $rest = $number % 100;

        if ($crore > 0) {
            $parts[] = self::convert($crore).' Crore';
        }

        if ($lakh > 0) {
            $parts[] = self::twoDigits($lakh).' Lakh';
        }

        if ($thousand > 0) {
            $parts[] = self::twoDigits($thousand).' Thousand';
        }

        if ($hundred > 0) {
            $parts[] = self::ONES[$hundred].' Hundred';
        }

        if ($rest > 0) {
            $parts[] = self::twoDigits($rest);
        }

        return implode(' ', $parts);
    }

    private static function twoDigits(int $number): string
    {
        if ($number < 20) {
            return self::ONES[$number];
        }

        $tens = self::TENS[intdiv($number, 10)];
        $ones = self::ONES[$number % 10];

        return $ones === '' ? $tens : $tens.' '.$ones;
    }
}
