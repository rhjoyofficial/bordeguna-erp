<?php

namespace App\Modules\Commerce\Support;

use App\Modules\Accounting\Support\Money;

/**
 * A bundle, priced: either a refusal, or its parts with their shares.
 */
final class ComboQuote
{
    /**
     * @param  list<ComboPart>  $parts
     */
    public function __construct(
        public readonly ?string $refusal,
        public readonly array $parts,
        public readonly Money $partsTotal,
        public readonly Money $total,
    ) {}

    public static function refused(string $reason): self
    {
        return new self($reason, [], Money::zero(), Money::zero());
    }

    public function sellable(): bool
    {
        return $this->refusal === null;
    }

    public function saving(): Money
    {
        return Money::ofMinor($this->partsTotal->minor - $this->total->minor);
    }

    /**
     * Whether the bundle can be sold, and what to tell a buyer about waiting,
     * in the shape `AvailabilityDecision::toArray()` gives a product - so a
     * template draws a bundle and a product with the same keys.
     */
    public function availability(): array
    {
        $madeToOrder = $this->sellable() && array_filter(array_map(fn (ComboPart $part) => $part->madeToOrder, $this->parts)) !== [];

        return (new AvailabilityDecision(
            sellable: $this->sellable(),
            reason: $this->refusal,
            madeToOrder: $madeToOrder,
        ))->toArray();
    }
}
