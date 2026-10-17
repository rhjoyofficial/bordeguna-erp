<?php

namespace App\Modules\Foundation\Corrections\Exceptions;

use App\Modules\Foundation\Corrections\DuplicateWarning;
use RuntimeException;

/**
 * The record looks like one saved recently, and this person has not said
 * "Save anyway" for exactly this payload and these candidates.
 */
class PossibleDuplicateException extends RuntimeException
{
    public function __construct(public readonly DuplicateWarning $warning)
    {
        parent::__construct('Possible duplicate.');
    }
}
