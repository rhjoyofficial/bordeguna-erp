<?php

namespace App\Modules\Sales\Exceptions;

use App\Modules\Accounting\Support\Money;
use RuntimeException;

/**
 * An order the credit rules refuse (21 Sep 2026): a customer who would go over
 * their credit limit, or a distributor who has not paid in advance.
 *
 * ⚠ A RuntimeException so every screen that approves an order already shows its
 * sentence; the figures ride along for the one that offers an Owner override.
 */
class CreditRefused extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $rule,
        public readonly ?Money $creditLimit,
        public readonly Money $owedBefore,
        public readonly Money $amount,
        public readonly Money $owedAfter,
    ) {
        parent::__construct($message);
    }
}
