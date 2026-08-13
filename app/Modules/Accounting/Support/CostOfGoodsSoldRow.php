<?php

namespace App\Modules\Accounting\Support;

/**
 * One product's cost of sales for the period, net of returns.
 */
final readonly class CostOfGoodsSoldRow
{
    public function __construct(
        public int $productId,
        public string $productSku,
        public string $productName,
        public ?string $brand,
        public string $quantity,
        public Money $cost,
        public Money $revenue,
    ) {}

    public function grossProfit(): Money
    {
        return Money::ofMinor($this->revenue->minor - $this->cost->minor);
    }

    /**
     * ⚠ Margin is NULL, never zero, when there is no revenue to divide by. A
     * displayed "0.0%" on a product that was never sold reads as a real, known
     * margin of nothing - and until `product_prices` is loaded that is every
     * product in the catalogue.
     */
    public function marginPercent(): ?string
    {
        if ($this->revenue->minor === 0) {
            return null;
        }

        return number_format($this->grossProfit()->minor / $this->revenue->minor * 100, 1, '.', '');
    }

    /**
     * Sold nothing and cost nothing. Dropped as noise.
     *
     * ⚠ Quantity is deliberately NOT part of this test. A product that moved
     * quantity but released no cost is a finding - goods leaving the shelf that
     * the books think were free - and must survive to be seen.
     */
    public function isEmpty(): bool
    {
        return $this->cost->isZero()
            && $this->revenue->isZero()
            && (float) $this->quantity === 0.0;
    }
}
