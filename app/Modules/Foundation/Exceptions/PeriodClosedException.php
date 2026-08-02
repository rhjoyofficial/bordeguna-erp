<?php

namespace App\Modules\Foundation\Exceptions;

use Illuminate\Support\Carbon;
use RuntimeException;

class PeriodClosedException extends RuntimeException
{
    public static function forDate(Carbon $attempted, Carbon $closedThrough): self
    {
        return new self(
            "The books are closed through {$closedThrough->toDateString()}, so nothing can be posted or backdated to {$attempted->toDateString()}. Ask the Founder to unlock the period if this is unavoidable."
        );
    }
}
