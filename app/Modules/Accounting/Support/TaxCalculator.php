<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Models\TaxClass;
use App\Modules\Foundation\Models\Business;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * THE one place VAT is calculated. Every document asks here; none does its own
 * arithmetic, so a sales invoice, a return, a supplier bill and the VAT report
 * cannot disagree about what fifteen percent of a figure is.
 *
 * ⚠⚠ THREE RULES, each of which is a decision recorded in
 * VAT-READY-ARCHITECTURE.md and none of which a caller may soften:
 *
 *  1. **VAT exists only from `vat_effective_from`, and only while the business
 *     is switched on (C-VAT-5).** A document dated before that day carries no
 *     VAT for ever, whatever the switch says on the day it is looked at.
 *  2. **A price either INCLUDES the VAT or has it ADDED ON TOP, and the document
 *     says which (C-VAT-1).** Inclusive extracts `gross x rate / (100 + rate)`;
 *     exclusive adds `net x rate / 100`. The customer of an inclusive price
 *     pays exactly the price; the customer of an exclusive one pays more.
 *  3. **No unknown state that silently charges nothing (C-VAT-11).** A product
 *     with no tax class takes the business's STANDARD class, and a STANDARD
 *     class with no rate in force REFUSES the document rather than taxing it at
 *     zero. ZERO and EXEMPT are zero by definition and need no rate row.
 *
 * Integer paisa throughout, rounded half away from zero, per line. A document's
 * VAT is the sum of its lines, so it always equals what the ledger posts.
 */
final class TaxCalculator
{
    public const STANDARD = 'STANDARD';

    public const ZERO = 'ZERO';

    public const EXEMPT = 'EXEMPT';

    /** @var array<string, string> "businessId|classId|date" => rate */
    private array $rates = [];

    /** Is VAT charged by this business on a document of this date? */
    public function appliesOn(?Business $business, Carbon $date): bool
    {
        // ⚠ Business::vatAppliesOn() is the one place the date rule lives. A
        // copy: it calls startOfDay() on what it is given.
        return $business !== null && $business->vatAppliesOn($date->copy());
    }

    /**
     * The percentage in force for a tax class on a date, as `15.0000`.
     *
     * @throws ValidationException when VAT is on and the standard rate was never set
     */
    public function rateFor(Business $business, ?int $taxClassId, Carbon $date): string
    {
        if (! $this->appliesOn($business, $date)) {
            return '0.0000';
        }

        $key = $business->id.'|'.($taxClassId ?? 0).'|'.$date->toDateString();

        return $this->rates[$key] ??= $this->resolveRate($business, $taxClassId, $date);
    }

    /**
     * Split an amount at a rate.
     *
     * @param  bool  $inclusive  true: `$amount` already contains the VAT
     */
    public function split(Money $amount, string $rate, bool $inclusive): TaxSplit
    {
        $rate4 = self::rate4($rate);

        if ($rate4 === 0 || $amount->isZero()) {
            return new TaxSplit($amount, Money::zero(), $amount, self::normalise($rate));
        }

        if ($inclusive) {
            $tax = Money::ofMinor(self::divideRounded($amount->minor * $rate4, 1_000_000 + $rate4));

            return new TaxSplit(Money::ofMinor($amount->minor - $tax->minor), $tax, $amount, self::normalise($rate));
        }

        $tax = Money::ofMinor(self::divideRounded($amount->minor * $rate4, 1_000_000));

        return new TaxSplit($amount, $tax, Money::ofMinor($amount->minor + $tax->minor), self::normalise($rate));
    }

    private function resolveRate(Business $business, ?int $taxClassId, Carbon $date): string
    {
        $class = $taxClassId !== null
            ? TaxClass::withoutGlobalScopes()->where('business_id', $business->id)->find($taxClassId)
            : null;

        // C-VAT-11: no class on the product means the business's standard one.
        $class ??= TaxClass::withoutGlobalScopes()
            ->where('business_id', $business->id)
            ->where('code', self::STANDARD)
            ->first();

        if ($class !== null && in_array($class->code, [self::ZERO, self::EXEMPT], true)) {
            return '0.0000';
        }

        $rate = $class?->rateOn($date);

        if ($rate === null) {
            throw ValidationException::withMessages([
                'vat' => sprintf(
                    'VAT is switched on from %s but no %s rate is in force on %s. Set the rate in Accounting > VAT settings first.',
                    $business->vat_effective_from->format('j M Y'),
                    $class?->name ?? 'standard',
                    $date->format('j M Y'),
                ),
            ]);
        }

        return self::normalise((string) $rate->rate);
    }

    /** `15` or `15.5` -> 150000 / 155000: the percentage in ten-thousandths. */
    private static function rate4(string $rate): int
    {
        [$whole, $fraction] = array_pad(explode('.', trim($rate), 2), 2, '');

        return (int) $whole * 10_000 + (int) str_pad(substr($fraction, 0, 4), 4, '0');
    }

    private static function normalise(string $rate): string
    {
        $rate4 = self::rate4($rate);

        return intdiv($rate4, 10_000).'.'.str_pad((string) ($rate4 % 10_000), 4, '0', STR_PAD_LEFT);
    }

    /** Half away from zero, the same rule Money uses everywhere else. */
    private static function divideRounded(int $numerator, int $divisor): int
    {
        $negative = $numerator < 0;
        $numerator = abs($numerator);
        $quotient = intdiv($numerator, $divisor);

        if (($numerator % $divisor) * 2 >= $divisor) {
            $quotient++;
        }

        return $negative ? -$quotient : $quotient;
    }
}
