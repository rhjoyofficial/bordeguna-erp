<?php

namespace App\Modules\Accounting\Support;

/**
 * One amount, split into what is the sale and what is the VAT.
 *
 * `net + tax = gross`, always, to the paisa: two of the three are calculated
 * and the third is the difference, so a rounding gap cannot exist.
 */
final class TaxSplit
{
    public function __construct(
        public readonly Money $net,
        public readonly Money $tax,
        public readonly Money $gross,
        /** The percentage frozen onto the document line, e.g. `15.0000`. */
        public readonly string $rate,
    ) {}

    public static function untaxed(Money $amount): self
    {
        return new self($amount, Money::zero(), $amount, '0.0000');
    }
}
