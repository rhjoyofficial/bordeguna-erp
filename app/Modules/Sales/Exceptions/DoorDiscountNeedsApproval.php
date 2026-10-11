<?php

namespace App\Modules\Sales\Exceptions;

use App\Modules\Accounting\Support\Money;
use RuntimeException;

/**
 * A discount at the door the confirming person may not give alone (courier hub
 * slice 8). Thrown from inside the outcome's transaction, so everything the
 * outcome had written is rolled back; the caller turns it into an Owner
 * approval request that keeps the whole answer.
 */
class DoorDiscountNeedsApproval extends RuntimeException
{
    public function __construct(
        public readonly Money $due,
        public readonly Money $collected,
        public readonly Money $discount,
        public readonly string $why,
    ) {
        parent::__construct($why);
    }
}
