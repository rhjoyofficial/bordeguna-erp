<?php

namespace App\Modules\Sales\Support;

use App\Models\User;

/**
 * ⭐ B17 (25 Oct 2026): a Manager or the Owner lets a counter bill or a direct
 * challan take stock that is held for a confirmed order, with a reason. The
 * permission is checked where it is used (`sales.stock.override`), never
 * trusted from the screen.
 */
final class ReservedStockPass
{
    public function __construct(
        public readonly User $by,
        public readonly string $reason,
    ) {}
}
