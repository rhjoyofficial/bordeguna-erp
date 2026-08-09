<?php

namespace App\Modules\Crm\Enums;

/**
 * ⚠⚠ There is no `deleted`. A task that will not be done is CANCELLED, and the
 * decision not to do it is information worth keeping.
 */
enum TaskStatus: string
{
    case Open = 'open';
    case Done = 'done';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Done => 'Done',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isClosed(): bool
    {
        return $this !== self::Open;
    }
}
