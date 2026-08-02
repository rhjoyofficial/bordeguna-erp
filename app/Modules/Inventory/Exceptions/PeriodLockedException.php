<?php

namespace App\Modules\Inventory\Exceptions;

use Illuminate\Support\Carbon;
use RuntimeException;

class PeriodLockedException extends RuntimeException
{
    public static function forDate(Carbon $attempted, Carbon $closedThrough): self
    {
        return new self(
            "Inventory is closed through {$closedThrough->toDateString()}, so nothing can be posted or backdated to {$attempted->toDateString()}. Ask the Founder to unlock the period if this is unavoidable."
        );
    }
}
