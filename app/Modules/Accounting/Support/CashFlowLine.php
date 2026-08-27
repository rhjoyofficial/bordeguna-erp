<?php

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Models\Account;

/**
 * One counterpart account and the cash it moved.
 *
 * ⚠ `flow` is signed from the business's point of view: positive is cash in,
 * negative is cash out. Presentation decides how to draw that; the number itself
 * never hides its direction behind a column heading.
 */
class CashFlowLine
{
    public function __construct(
        public readonly Account $account,
        public readonly Money $flow,
    ) {}

    public function isInflow(): bool
    {
        return $this->flow->minor > 0;
    }
}
