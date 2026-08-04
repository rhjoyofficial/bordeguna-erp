<?php

namespace App\Modules\Sales\Exceptions;

use DomainException;
use Illuminate\Support\Carbon;

/**
 * Nothing prices this product on this date - not for this customer, not for
 * their group, and not by default.
 *
 * ⚠ A refusal, not a zero. Selling at a price nobody set is how a counter gives
 * stock away, and a zero-price sale still posts cost of goods sold - so the
 * quiet version of this bug is a real loss that balances perfectly and reads as
 * a bad margin months later.
 *
 * ⚠ The message names the product AND the date, because the usual cause is a
 * price whose `effective_to` has passed rather than a price nobody ever set.
 */
class NoSellingPriceException extends DomainException
{
    public function __construct(
        public readonly int $productId,
        public readonly string $productName,
        public readonly Carbon $on,
    ) {
        parent::__construct(
            "No selling price is in force for {$productName} on {$on->toDateString()}."
        );
    }

    public static function for(int $productId, string $productName, Carbon $on): self
    {
        return new self($productId, $productName, $on);
    }
}
