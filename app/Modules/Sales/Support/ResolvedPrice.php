<?php

namespace App\Modules\Sales\Support;

use App\Modules\Sales\Models\ProductPrice;

/**
 * A price, and the rule that produced it.
 *
 * ⚠ The RULE travels with the number on purpose. "Why is it this price?" is the
 * first question anybody asks at a counter, and answering it from a bare decimal
 * means guessing at the resolution afterwards - which is a second implementation
 * of the resolver, written under pressure, in a screen.
 */
final readonly class ResolvedPrice
{
    public function __construct(
        public string $unitPrice,
        public ProductPrice $rule,
    ) {}

    /**
     * Which tier answered: `customer`, `price_group` or `default`.
     */
    public function tier(): string
    {
        return match ($this->rule->specificity()) {
            3 => 'customer',
            2 => 'price_group',
            default => 'default',
        };
    }

    /**
     * A short human explanation, for a tooltip or an audit line.
     */
    public function explain(): string
    {
        return match ($this->tier()) {
            'customer' => 'Agreed price for this customer',
            'price_group' => "Price for group [{$this->rule->price_group}]",
            default => 'Standard selling price',
        }.((float) $this->rule->min_quantity > 0
            ? " from {$this->rule->min_quantity} units"
            : '');
    }
}
