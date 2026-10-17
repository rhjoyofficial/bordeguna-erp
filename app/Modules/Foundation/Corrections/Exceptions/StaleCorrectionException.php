<?php

namespace App\Modules\Foundation\Corrections\Exceptions;

use RuntimeException;

/** The record changed after the person looked at it (PHASE-0-CONTRACT 1.4). Nothing was written. */
class StaleCorrectionException extends RuntimeException
{
    public const MESSAGE = 'This record changed after you opened it. Please look again.';

    public function __construct(?string $message = null, public readonly string $why = 'stale')
    {
        parent::__construct($message ?? self::MESSAGE);
    }
}
