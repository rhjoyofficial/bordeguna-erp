<?php

namespace App\Modules\Commerce\Support;

use App\Modules\Accounting\Support\Money;
use App\Modules\Inventory\Models\Product;

/**
 * One product in a priced bundle: the order line it will become.
 */
final class ComboPart
{
    public function __construct(
        public readonly Product $product,
        public readonly int $quantity,
        public readonly string $unitPrice,
        public readonly Money $gross,
        public readonly Money $discount,
        public readonly ?int $leadTimeDays,
        public readonly bool $madeToOrder = false,
    ) {}

    public function net(): Money
    {
        return Money::ofMinor($this->gross->minor - $this->discount->minor);
    }

    public function withDiscount(Money $discount): self
    {
        return new self($this->product, $this->quantity, $this->unitPrice, $this->gross, $discount, $this->leadTimeDays, $this->madeToOrder);
    }
}
