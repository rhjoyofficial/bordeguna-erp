<?php

namespace App\Modules\Hrm\Enums;

/**
 * ⚠ `OnHold` is not `Closed`. A role paused for budget is one somebody will
 * reopen; a closed one is finished. Collapsing them loses the difference between
 * "not now" and "no".
 */
enum JobOpeningStatus: string
{
    case Open = 'open';
    case OnHold = 'on_hold';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::OnHold => 'On hold',
            self::Closed => 'Closed',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
