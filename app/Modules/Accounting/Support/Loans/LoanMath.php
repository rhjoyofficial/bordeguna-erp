<?php

namespace App\Modules\Accounting\Support\Loans;

use InvalidArgumentException;

/**
 * Whole-number interest arithmetic for the Loan Register.
 *
 * ⚠⚠ INTEGER, NEVER FLOAT, the Money rule: a rate is held in ten-thousandths
 * of a percent (12.5% is 125000) and money in paisa, and every product is
 * divided once, rounded half up, at the end.
 *
 * ⚠ The products are split before they are multiplied so no intermediate
 * value can pass PHP's 64-bit limit: a hundred crore taka for thirty years at
 * 100% still fits (mulDivRound).
 */
final class LoanMath
{
    /** 1% in rate units. */
    public const PERCENT = 10000;

    /** 100% in rate units: the denominator that turns a rate into a fraction. */
    public const WHOLE = 1000000;

    /**
     * "12.5" -> 125000. Four decimal places at most; more is refused rather
     * than rounded, because a rate somebody typed is a contract term.
     */
    public static function rateE4(string $rate): int
    {
        $raw = trim(str_replace([',', ' ', '%'], '', $rate));

        if ($raw === '') {
            return 0;
        }

        if (! preg_match('/^\d+(\.\d+)?$/', $raw)) {
            throw new InvalidArgumentException("[{$rate}] is not a rate.");
        }

        [$whole, $fraction] = str_contains($raw, '.') ? explode('.', $raw, 2) : [$raw, ''];
        $fraction = rtrim($fraction, '0');

        if (strlen($fraction) > 4) {
            throw new InvalidArgumentException("[{$rate}] has more than four decimal places.");
        }

        return ((int) $whole) * self::PERCENT + (int) str_pad($fraction, 4, '0');
    }

    /** 125000 -> "12.5". */
    public static function rateString(int $rateE4): string
    {
        $whole = intdiv($rateE4, self::PERCENT);
        $fraction = rtrim(str_pad((string) ($rateE4 % self::PERCENT), 4, '0', STR_PAD_LEFT), '0');

        return $fraction === '' ? (string) $whole : "{$whole}.{$fraction}";
    }

    /**
     * round(x * a / den), half away from zero, for x of either sign and
     * non-negative a and den.
     */
    public static function mulDivRound(int $x, int $a, int $den): int
    {
        if ($den <= 0) {
            throw new InvalidArgumentException('The denominator must be positive.');
        }

        $negative = $x < 0;
        $x = abs($x);

        $quotient = intdiv($x, $den);
        $remainder = $x % $den;

        // quotient*a is exact; remainder*a stays under den*a, which is small.
        $result = $quotient * $a + intdiv(2 * $remainder * $a + $den, 2 * $den);

        return $negative ? -$result : $result;
    }

    /**
     * Interest on "balance x days" (paisa-days) at a rate over a year basis:
     * round(paisaDays * rateE4 / (1,000,000 * basis)).
     */
    public static function interest(int $paisaDays, int $rateE4, int $basis): int
    {
        if ($rateE4 === 0 || $paisaDays === 0) {
            return 0;
        }

        return self::mulDivRound($paisaDays, $rateE4, self::WHOLE * $basis);
    }

    /** A percentage of an amount: TDS at 10% of gross interest. */
    public static function percentOf(int $minor, int $rateE4): int
    {
        return self::mulDivRound($minor, $rateE4, self::WHOLE);
    }
}
