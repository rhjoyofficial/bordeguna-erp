<?php

namespace App\Modules\Hrm\Enums;

/**
 * How much of a day a leave request covers (LEV-04).
 *
 * LEV-04's recommendation keeps full/half-day leave and time-based movement as
 * separate workflows, so this deliberately does not model hours — a part-day
 * absence for an errand is a Movement, not a fraction of a leave day.
 */
enum LeaveDayPortion: string
{
    case FullDay = 'full_day';
    case FirstHalf = 'first_half';
    case SecondHalf = 'second_half';

    public function label(): string
    {
        return match ($this) {
            self::FullDay => 'Full day',
            self::FirstHalf => 'First half',
            self::SecondHalf => 'Second half',
        };
    }

    public function days(): float
    {
        return $this === self::FullDay ? 1.0 : 0.5;
    }

    /**
     * A half day only makes sense on a single-date request.
     */
    public function isPartial(): bool
    {
        return $this !== self::FullDay;
    }
}
