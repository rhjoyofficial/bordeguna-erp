<?php

namespace App\Modules\Accounting\Support;

/**
 * "Taka One Lakh Twenty Thousand Five Hundred and Paisa Fifty Only".
 *
 * ⚠ The South Asian grouping (thousand, lakh, crore), because that is how a
 * Bangladeshi voucher and a Bangladeshi cheque are written. "One hundred and
 * twenty thousand" on a payment voucher reads as a foreign document.
 */
final class AmountInWords
{
    private const ONES = [
        '', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
        'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen',
    ];

    private const TENS = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    public static function taka(Money $amount): string
    {
        $minor = abs($amount->minor);
        $taka = intdiv($minor, 100);
        $paisa = $minor % 100;

        $words = 'Taka '.($taka === 0 ? 'Zero' : self::number($taka));

        if ($paisa > 0) {
            $words .= ' and Paisa '.self::number($paisa);
        }

        return $words.' Only';
    }

    private static function number(int $n): string
    {
        $parts = [];

        // Crore repeats above itself (a hundred crore = "One Hundred Crore").
        $crore = intdiv($n, 10000000);
        $n %= 10000000;

        if ($crore > 0) {
            $parts[] = self::number($crore).' Crore';
        }

        foreach ([100000 => 'Lakh', 1000 => 'Thousand', 100 => 'Hundred'] as $unit => $name) {
            $count = intdiv($n, $unit);
            $n %= $unit;

            if ($count > 0) {
                $parts[] = self::belowHundred($count).' '.$name;
            }
        }

        if ($n > 0) {
            $parts[] = self::belowHundred($n);
        }

        return implode(' ', $parts);
    }

    private static function belowHundred(int $n): string
    {
        if ($n < 20) {
            return self::ONES[$n];
        }

        return trim(self::TENS[intdiv($n, 10)].' '.self::ONES[$n % 10]);
    }
}
