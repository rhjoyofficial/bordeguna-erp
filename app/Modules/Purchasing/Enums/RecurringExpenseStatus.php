<?php

namespace App\Modules\Purchasing\Enums;

/**
 * ⚠ Ended is kept, never deleted. A subscription that stopped in March still
 * explains March's costs, and a business that cancels something wants to see
 * what it used to pay.
 */
enum RecurringExpenseStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Ended = 'ended';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Paused => 'Paused',
            self::Ended => 'Ended',
        };
    }

    /** Only an active schedule generates anything new. */
    public function generates(): bool
    {
        return $this === self::Active;
    }
}
