<?php

namespace App\Modules\Commerce\Support;

use App\Modules\Accounting\Support\Money;
use App\Modules\Commerce\Models\StoreCoupon;
use App\Modules\Inventory\Models\Product;

/**
 * A coupon against one basket: a refusal, or what it takes off.
 */
final class CouponQuote
{
    /**
     * @param  ?array{product: Product, quantity: int, gross: Money}  $gift
     */
    public function __construct(
        public readonly string $code,
        public readonly ?string $refusal,
        public readonly ?StoreCoupon $coupon,
        public readonly Money $discount,
        public readonly bool $freeDelivery,
        public readonly ?array $gift,
        public readonly ?string $giftProblem,
    ) {}

    public static function refused(string $code, string $reason): self
    {
        return new self($code, $reason, null, Money::zero(), false, null, null);
    }

    public function applies(): bool
    {
        return $this->refusal === null;
    }

    /**
     * Everything the coupon takes off the order, as ONE order-level discount:
     * the discount itself plus the value of any gift given with it. See
     * `CouponPricing` for why a gift is an order discount rather than a zero
     * price.
     */
    public function orderDiscount(): Money
    {
        return Money::ofMinor($this->discount->minor + ($this->gift['gross'] ?? Money::zero())->minor);
    }
}
