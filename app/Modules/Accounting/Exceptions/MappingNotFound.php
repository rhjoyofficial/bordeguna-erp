<?php

namespace App\Modules\Accounting\Exceptions;

use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * ⚠⚠ A business event that cannot be mapped STOPS. It does not post to a
 * suspense account and wait to be noticed.
 *
 * The whole value of an automatic accrual is that it is complete: a wage bill
 * missing one component is not a smaller wage bill, it is a wrong one. Failing
 * the approval is loud, immediate and fixable in one row of configuration;
 * posting an incomplete journal is quiet and found at year end.
 */
class MappingNotFound extends RuntimeException
{
    public static function for(string $key, Carbon $on): self
    {
        return new self(
            "No account is mapped to [{$key}] on {$on->toDateString()}. ".
            'Set it up under Accounting before posting this.'
        );
    }
}
