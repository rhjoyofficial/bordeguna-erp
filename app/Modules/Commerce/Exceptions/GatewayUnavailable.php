<?php

namespace App\Modules\Commerce\Exceptions;

use RuntimeException;

/**
 * The payment gateway could not open a payment page. The order stands, unpaid,
 * and the customer can try again.
 */
final class GatewayUnavailable extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
