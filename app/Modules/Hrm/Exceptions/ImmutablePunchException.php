<?php

namespace App\Modules\Hrm\Exceptions;

use RuntimeException;

/**
 * Thrown when anything tries to change or remove a raw punch (ATT-03).
 *
 * This is deliberately a hard failure rather than a silent no-op: a caller
 * that thinks it edited a punch and did not would produce attendance nobody
 * can reconcile. Corrections belong in their own records (Phase 2c).
 */
class ImmutablePunchException extends RuntimeException
{
    public static function forUpdate(): self
    {
        return new self(
            'Raw attendance punches are immutable (ATT-03). Record a correction instead of editing the punch.'
        );
    }

    public static function forDelete(): self
    {
        return new self(
            'Raw attendance punches are immutable (ATT-03) and cannot be deleted. Record a correction instead.'
        );
    }
}
