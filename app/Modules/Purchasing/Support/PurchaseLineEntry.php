<?php

namespace App\Modules\Purchasing\Support;

use App\Modules\Accounting\Support\Money;
use App\Modules\Purchasing\Enums\PurchaseEntryMode;
use InvalidArgumentException;

/**
 * The arithmetic of a purchase line typed either way: "100 kg at 500" or
 * "100 kg for 50,000" (Phase 1 part A, 17 Oct 2026; ChatGPT ruling Q1).
 *
 * ⚠⚠ ONE AUTHORITATIVE FIGURE PER LINE. When the price was typed, the total is
 * quantity x price rounded once to the paisa through Money. When the total was
 * typed, the total is kept to the paisa exactly and the price is a six-place
 * DERIVATION: 100,000 for 3,000 units shows 33.333333, and multiplying that
 * back gives 99,999.999, which is why the total is stored and never rebuilt.
 * Purchase order totals, GRNI, receipt costing and the bill-vs-order variance
 * all read the stored total.
 *
 * ⚠ Integer arithmetic throughout, the same rule as Money and FIFO: a float in
 * this path becomes a paisa nobody can explain on a ledger that has to tie.
 */
final class PurchaseLineEntry
{
    /** Quantities are decimal(15,4). */
    private const QUANTITY_SCALE = 10000;

    /** The unit cost column is decimal(15,6) since 17 Oct 2026. */
    public const RATE_PLACES = 6;

    /**
     * Turns what was typed into what is stored.
     *
     * @return array{entry_mode: PurchaseEntryMode, unit_cost: string, line_total: Money}
     */
    public static function resolve(string $quantity, ?string $unitCost, ?string $lineTotal, PurchaseEntryMode|string|null $entryMode = null): array
    {
        $mode = self::modeOf($unitCost, $lineTotal, $entryMode);

        if (! self::isNumber($quantity) || self::scaled($quantity) <= 0) {
            throw new InvalidArgumentException('A purchase line needs a quantity greater than zero.');
        }

        if ($mode === PurchaseEntryMode::Total) {
            if (! self::isNumber($lineTotal)) {
                throw new InvalidArgumentException('Type the line total, or type the unit price instead.');
            }

            $total = Money::ofTaka($lineTotal);

            if ($total->isCredit()) {
                throw new InvalidArgumentException('A line total cannot be negative.');
            }

            return [
                'entry_mode' => $mode,
                'unit_cost' => self::unitCostFromTotal($total, $quantity),
                'line_total' => $total,
            ];
        }

        if (! self::isNumber($unitCost)) {
            throw new InvalidArgumentException('Type the unit price, or type the line total instead.');
        }

        if (str_starts_with(trim((string) $unitCost), '-')) {
            throw new InvalidArgumentException('A unit price cannot be negative.');
        }

        // ⚠ Six places, rounded half away from zero BEFORE the multiplication,
        // so the stored rate and the stored total are made of the same number
        // on every database driver (MySQL would round a seventh place on its
        // own; SQLite would keep it).
        $rate = self::roundTo((string) $unitCost, self::RATE_PLACES);

        return [
            'entry_mode' => $mode,
            'unit_cost' => $rate,
            'line_total' => Money::ofProduct($quantity, $rate),
        ];
    }

    /**
     * The same refusals as resolve(), as field => sentence, for a form that
     * wants each one under the box it is about.
     *
     * @return array<string, string>
     */
    public static function problems(string $quantity, ?string $unitCost, ?string $lineTotal, PurchaseEntryMode|string|null $entryMode = null): array
    {
        $mode = self::modeOf($unitCost, $lineTotal, $entryMode);

        try {
            self::resolve($quantity, $unitCost, $lineTotal, $entryMode);
        } catch (InvalidArgumentException $e) {
            $field = str_contains($e->getMessage(), 'quantity')
                ? 'quantity'
                : ($mode === PurchaseEntryMode::Total ? 'line_total' : 'unit_cost');

            return [$field => $e->getMessage()];
        }

        return [];
    }

    /**
     * Which figure is the truth. Stated explicitly by the form; inferred for
     * callers that only ever passed a unit cost (every line before 17 Oct 2026).
     */
    public static function modeOf(?string $unitCost, ?string $lineTotal, PurchaseEntryMode|string|null $entryMode): PurchaseEntryMode
    {
        if ($entryMode instanceof PurchaseEntryMode) {
            return $entryMode;
        }

        if (is_string($entryMode) && trim($entryMode) !== '') {
            return PurchaseEntryMode::tryFrom(trim($entryMode))
                ?? throw new InvalidArgumentException("[{$entryMode}] is not a way of entering a purchase line.");
        }

        return self::isNumber($lineTotal) && ! self::isNumber($unitCost)
            ? PurchaseEntryMode::Total
            : PurchaseEntryMode::Rate;
    }

    /**
     * The six-place unit cost a typed total implies: total / quantity.
     *
     * ⚠ For DISPLAY and for costing a batch, never for rebuilding the total.
     */
    public static function unitCostFromTotal(Money $total, string $quantity): string
    {
        $scaledQuantity = self::scaled($quantity);

        if ($scaledQuantity === 0) {
            return self::roundTo('0', self::RATE_PLACES);
        }

        // paisa (1e2) to taka at 1e6, over a quantity scaled by 1e4: x 1e8.
        $factor = 10 ** (self::RATE_PLACES + 4 - 2);

        if (abs($total->minor) > intdiv(PHP_INT_MAX, $factor)) {
            throw new InvalidArgumentException("A line total of {$total->format()} is too large to price per unit precisely.");
        }

        return self::unscale(self::divideRounded($total->minor * $factor, $scaledQuantity), 10 ** self::RATE_PLACES, self::RATE_PLACES);
    }

    /**
     * The share of a stored line total that a PART of its quantity is worth:
     * 1,000 of 3,000 units bought for 100,000 is 33,333.33.
     *
     * ⚠ Used only for a line entered by total. A line entered by rate values a
     * part as quantity x rate, which is what the supplier's own invoice for a
     * part delivery says. Either way the FULL quantity is the stored total
     * exactly (PurchaseOrderLine::valueOf).
     */
    public static function shareOf(Money $lineTotal, string $quantity, string $fullQuantity): Money
    {
        $part = self::scaled($quantity);
        $whole = self::scaled($fullQuantity);

        if ($whole === 0 || $part === 0) {
            return Money::zero();
        }

        if ($part === $whole) {
            return $lineTotal;
        }

        $minor = abs($lineTotal->minor);

        // minor x part / whole in two steps so a large line never overflows:
        // the whole-quotient part exactly, the remainder rounded.
        $quotient = intdiv($minor, $whole);
        $remainder = $minor % $whole;

        if ($remainder !== 0 && $part > intdiv(PHP_INT_MAX, $remainder)) {
            throw new InvalidArgumentException("A quantity of {$quantity} is too large to share a line total precisely.");
        }

        $share = $quotient * $part + self::divideRounded($remainder * $part, $whole);

        return Money::ofMinor($lineTotal->minor < 0 ? -$share : $share);
    }

    /**
     * "12.3456789" to "12.345679": string arithmetic, half away from zero,
     * never (float).
     */
    public static function roundTo(string $value, int $places): string
    {
        $raw = trim(str_replace([',', ' '], '', $value));

        if (! preg_match('/^[+-]?\d+(\.\d+)?$/', $raw)) {
            throw new InvalidArgumentException("[{$value}] is not a number.");
        }

        $negative = str_starts_with($raw, '-');
        $raw = ltrim($raw, '+-');

        [$whole, $fraction] = str_contains($raw, '.') ? explode('.', $raw, 2) : [$raw, ''];

        $kept = substr(str_pad($fraction, $places + 1, '0'), 0, $places);
        $next = (int) substr(str_pad($fraction, $places + 1, '0'), $places, 1);

        // The kept digits as one integer, carried into the whole part when the
        // rounding overflows them (0.9999995 at six places is 1.000000).
        $scaled = (int) ($whole.$kept);

        if ($next >= 5) {
            $scaled++;
        }

        $scaled = (string) $scaled;
        $scaled = str_pad($scaled, $places + 1, '0', STR_PAD_LEFT);

        $text = substr($scaled, 0, -$places).($places > 0 ? '.'.substr($scaled, -$places) : '');

        return ($negative && (int) str_replace('.', '', $text) !== 0 ? '-' : '').$text;
    }

    public static function isNumber(?string $value): bool
    {
        return $value !== null && preg_match('/^[+-]?\d+(\.\d+)?$/', trim(str_replace([',', ' '], '', $value))) === 1;
    }

    /** A decimal(15,4) quantity as an integer, exactly. */
    public static function scaled(string $quantity): int
    {
        $raw = self::roundTo($quantity, 4);
        $negative = str_starts_with($raw, '-');
        $digits = str_replace(['-', '.'], '', $raw);

        return $negative ? -((int) $digits) : (int) $digits;
    }

    private static function unscale(int $scaled, int $scale, int $places): string
    {
        $negative = $scaled < 0;
        $digits = str_pad((string) abs($scaled), $places + 1, '0', STR_PAD_LEFT);

        return ($negative ? '-' : '').substr($digits, 0, -$places).'.'.substr($digits, -$places);
    }

    /** Round half away from zero, the same rule Money uses. */
    private static function divideRounded(int $numerator, int $divisor): int
    {
        $negative = ($numerator < 0) !== ($divisor < 0);
        $numerator = abs($numerator);
        $divisor = abs($divisor);

        $result = intdiv($numerator, $divisor);

        if (($numerator % $divisor) * 2 >= $divisor) {
            $result++;
        }

        return $negative ? -$result : $result;
    }
}
