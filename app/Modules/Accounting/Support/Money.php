<?php

namespace App\Modules\Accounting\Support;

use InvalidArgumentException;

/**
 * An amount of money, held as a whole number of paisa.
 *
 * ⚠⚠ THE LEDGER DOES NOT STORE DECIMALS, AND THIS IS THE REASON.
 *
 * A journal lives or dies by an EXACT equality: debits must equal credits, not
 * approximately, not to within a tolerance. That is the single comparison
 * floating point cannot be trusted with, and this project has already paid for
 * five separate decimal incidents - three of stringification, one of float
 * arithmetic inside FIFO, one avoided only because a value was stored rather
 * than recomputed. In integers the equality is true or it is not, the database
 * can enforce it, and no model cast has to quietly rescue it.
 *
 * ⚠ Positive is a DEBIT, negative is a CREDIT. One signed number rather than two
 * columns, because the invariant is then a single predicate (`SUM = 0`) instead
 * of two - the second of which ("exactly one of debit and credit is non-zero")
 * is the one nobody checks until a line carries both. Debit and credit are how a
 * journal is PRINTED, and printing is a presenter's job.
 *
 * ⚠ Immutable. Every operation returns a new instance, so an amount cannot be
 * changed after the balance check that approved it.
 */
final class Money
{
    private function __construct(public readonly int $minor) {}

    public static function ofMinor(int $minor): self
    {
        return new self($minor);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * Parses an amount of taka.
     *
     * ⚠⚠ DELIBERATELY STRING ARITHMETIC, never (float) or round(). The whole
     * point of this class is not to let a decimal amount pass through a float on
     * its way in: `(int) ((float) '0.29' * 100)` is 28 in PHP, which is not a
     * rounding error anybody would predict from reading the code.
     *
     * ⚠ Anything finer than a paisa is rounded HALF AWAY FROM ZERO, and the
     * caller is expected to know it happened. Inventory carries costs to four
     * decimal places, so a posting derived from stock CAN lose a fraction of a
     * paisa - and in Phase 3 that residual becomes an explicit line on the
     * Rounding Differences account rather than being absorbed silently into
     * whichever line happened to be computed last.
     */
    public static function ofTaka(string|int $amount): self
    {
        $raw = trim(str_replace([',', ' '], '', (string) $amount));

        if ($raw === '') {
            throw new InvalidArgumentException('An amount is required.');
        }

        if (! preg_match('/^[+-]?\d+(\.\d+)?$/', $raw)) {
            throw new InvalidArgumentException("[{$amount}] is not an amount of money.");
        }

        $negative = str_starts_with($raw, '-');
        $raw = ltrim($raw, '+-');

        [$whole, $fraction] = str_contains($raw, '.') ? explode('.', $raw, 2) : [$raw, ''];

        // Three digits: two of paisa and one to decide the rounding.
        $fraction = substr($fraction.'000', 0, 3);
        $minor = ((int) $whole) * 100 + (int) substr($fraction, 0, 2);

        if ((int) $fraction[2] >= 5) {
            $minor++;
        }

        return new self($negative ? -$minor : $minor);
    }

    /**
     * The product of two decimal amounts, as money.
     *
     * ⚠⚠ INTEGER-SCALED, NEVER FLOAT. `quantity * unit_cost` is the single most
     * dangerous multiplication in this codebase: both carry four decimal places,
     * and doing it in floating point is how a stock value acquires a tail of
     * 2.78e-17. Inventory already learned this in FIFO and fixed it by scaling
     * to integers; this is the same fix at the ledger boundary.
     *
     * ⚠ Deliberately not bcmath. The extension is not guaranteed to be loaded,
     * and a money path that silently changes behaviour with a php.ini is worse
     * than one that does its own arithmetic in twenty lines.
     */
    public static function ofProduct(string $a, string $b): self
    {
        [$aScaled, $aScale] = self::scale($a);
        [$bScaled, $bScale] = self::scale($b);

        $product = $aScaled * $bScaled;              // scaled by 10^($aScale + $bScale)
        $divisor = 10 ** ($aScale + $bScale - 2);    // leaving paisa

        if ($divisor < 1) {
            return new self($product * (10 ** (2 - $aScale - $bScale)));
        }

        $negative = $product < 0;
        $product = abs($product);

        // Round half away from zero, the same rule as ofTaka.
        $minor = intdiv($product, $divisor);

        if (($product % $divisor) * 2 >= $divisor) {
            $minor++;
        }

        return new self($negative ? -$minor : $minor);
    }

    /**
     * "12.3400" becomes [123400, 4].
     *
     * @return array{0: int, 1: int}
     */
    private static function scale(string $decimal): array
    {
        $raw = trim(str_replace([',', ' '], '', $decimal));

        if (! preg_match('/^[+-]?\d+(\.\d+)?$/', $raw)) {
            throw new InvalidArgumentException("[{$decimal}] is not a number.");
        }

        $negative = str_starts_with($raw, '-');
        $raw = ltrim($raw, '+-');

        [$whole, $fraction] = str_contains($raw, '.') ? explode('.', $raw, 2) : [$raw, ''];

        $scaled = (int) ($whole.$fraction);

        return [$negative ? -$scaled : $scaled, strlen($fraction)];
    }

    public function plus(self $other): self
    {
        return new self($this->minor + $other->minor);
    }

    public function negated(): self
    {
        return new self(-$this->minor);
    }

    public function absolute(): self
    {
        return new self(abs($this->minor));
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isDebit(): bool
    {
        return $this->minor > 0;
    }

    public function isCredit(): bool
    {
        return $this->minor < 0;
    }

    /**
     * @param  iterable<self>  $amounts
     */
    public static function sum(iterable $amounts): self
    {
        $total = 0;

        foreach ($amounts as $amount) {
            $total += $amount->minor;
        }

        return new self($total);
    }

    /**
     * "1234.56" - the machine-readable form, for storage and comparison.
     */
    public function toTaka(): string
    {
        $sign = $this->minor < 0 ? '-' : '';
        $absolute = abs($this->minor);

        return $sign.intdiv($absolute, 100).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * "1,234.56" - the human form.
     *
     * ⚠ Every money figure that reaches a user goes through explicit formatting.
     * `19200.00000000` has reached a real user on this project twice, in two
     * different modules, the second time because fixing an instance was mistaken
     * for fixing a pattern.
     */
    public function format(): string
    {
        // ⚠ Built from the exact string, NOT from `$this->minor / 100`. Dividing
        // puts the amount back through a float on its way to the screen, which
        // is the trip this class exists to avoid.
        [$whole, $paisa] = explode('.', $this->toTaka(), 2);

        $negative = str_starts_with($whole, '-');

        return ($negative ? '-' : '').number_format((int) ltrim($whole, '-')).'.'.$paisa;
    }
}
