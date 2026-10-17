<?php

namespace App\Modules\Foundation\Corrections\Exceptions;

use RuntimeException;

/** The preview token is missing, expired, or belongs to another person or another payload. */
class PreviewExpiredException extends RuntimeException
{
    public const MESSAGE = 'Please preview again.';

    public function __construct(public readonly string $why = 'expired')
    {
        parent::__construct(self::MESSAGE);
    }
}
